<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Core\Database;

/**
 * Rule-based email classifier (no AI): each category has weighted keywords
 * (email_categories.keywords, editable by the Admin Head). Whole-word matches in
 * the subject count double. The best-scoring category wins; with no match the
 * fallback category (OTHER) is used.
 *
 * Confidence (0-100) = share of the winning score among all matches, scaled
 * down when the evidence is thin:  best / (best + runner-up) x min(1, best / 6).
 * Below REVIEW_BELOW the email is flagged "needs review" on the Mail screen.
 */
final class Classifier
{
    public const REVIEW_BELOW = 50;
    private const STRONG_SCORE = 6;

    /** @var list<array{id: int, code: string, name: string, terms: list<array{term: string, weight: int}>, fallback: bool}> */
    private array $categories;

    public function __construct(?array $categories = null)
    {
        $this->categories = $categories ?? self::load();
    }

    /** @return list<array{id: int, code: string, name: string, terms: list<array{term: string, weight: int}>, fallback: bool}> */
    public static function load(): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT id, code, name, keywords, is_fallback FROM email_categories WHERE status = 'active' ORDER BY sort_order, id") as $c) {
            $terms = [];
            foreach (json_decode((string) $c['keywords'], true) ?: [] as $k) {
                $term = trim(mb_strtolower((string) ($k['term'] ?? '')));
                if ($term !== '') {
                    $terms[] = ['term' => $term, 'weight' => max(1, min(10, (int) ($k['weight'] ?? 1)))];
                }
            }
            $out[] = ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'terms' => $terms, 'fallback' => (bool) $c['is_fallback']];
        }
        return $out;
    }

    /**
     * @return array{category_id: ?int, confidence: ?float, method: string, scores: array<string, int>, matched: list<string>}
     */
    public function classify(?string $subject, ?string $body): array
    {
        $subject = mb_strtolower((string) $subject);
        $body = mb_strtolower((string) $body);
        $scores = [];
        $matched = [];
        foreach ($this->categories as $c) {
            $score = 0;
            foreach ($c['terms'] as $t) {
                $re = '/(?<![\p{L}\p{N}])' . preg_quote($t['term'], '/') . '(?![\p{L}\p{N}])/u';
                $inSubject = min(3, preg_match_all($re, $subject));
                $inBody = min(3, preg_match_all($re, $body));
                if ($inSubject + $inBody > 0) {
                    $score += $t['weight'] * (2 * $inSubject + $inBody);
                    $matched[] = $t['term'];
                }
            }
            $scores[$c['code']] = $score;
        }

        arsort($scores);
        $codes = array_keys($scores);
        $best = $scores[$codes[0] ?? ''] ?? 0;
        if ($best === 0) {
            $fallback = array_values(array_filter($this->categories, static fn ($c) => $c['fallback']))[0] ?? null;
            return ['category_id' => $fallback['id'] ?? null, 'confidence' => null, 'method' => 'none', 'scores' => $scores, 'matched' => []];
        }
        $second = $scores[$codes[1] ?? ''] ?? 0;
        $confidence = round(100 * $best / ($best + $second) * min(1, $best / self::STRONG_SCORE), 2);
        $winner = array_values(array_filter($this->categories, static fn ($c) => $c['code'] === $codes[0]))[0];
        return ['category_id' => $winner['id'], 'confidence' => min(99.0, $confidence), 'method' => 'rule', 'scores' => $scores, 'matched' => array_values(array_unique($matched))];
    }
}
