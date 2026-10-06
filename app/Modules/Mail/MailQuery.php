<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Core\Database;
use App\Core\DataScope;

/**
 * The single definition of "which emails" for the Mail screen AND the dashboard
 * Email cards, so a card's count always equals the list it opens.
 *
 * Visibility: the user's data scope on the email's branch / employee, plus any
 * email currently assigned to the user personally.
 */
final class MailQuery
{
    public const OPEN_STATUSES = ['new', 'open', 'in_progress'];

    /**
     * @param array{category?: ?string, status?: ?string, open?: bool, from?: ?string, to?: ?string, branch?: ?int,
     *              employee?: ?int, customer?: ?int, q?: ?string, review?: bool, mine?: bool} $f
     * @return array{0: string, 1: list<mixed>} SQL condition on alias m
     */
    public static function where(array $user, array $f): array
    {
        $scope = DataScope::for($user);
        [$sql, $params] = $scope->where('m.branch_id', 'm.employee_id');
        $parts = [];
        if ($scope->isUnrestricted()) {
            $parts[] = $sql;
        } else {
            $parts[] = "({$sql} OR EXISTS (SELECT 1 FROM email_assignments ea WHERE ea.email_id = m.id AND ea.is_current = 1 AND ea.assigned_to = ?))";
            $params[] = (int) $user['id'];
        }
        if (!empty($f['category'])) {
            $parts[] = 'm.category_id = (SELECT id FROM email_categories WHERE code = ?)';
            $params[] = $f['category'];
        }
        if (!empty($f['status'])) {
            $parts[] = 'm.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['open'])) {
            $parts[] = "m.status IN ('new', 'open', 'in_progress')";
        }
        if (!empty($f['from'])) {
            $parts[] = 'm.received_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $parts[] = 'm.received_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $f['to'];
        }
        foreach (['branch' => 'm.branch_id', 'employee' => 'm.employee_id', 'customer' => 'm.customer_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $parts[] = "{$col} = ?";
                $params[] = (int) $f[$k];
            }
        }
        if (!empty($f['review'])) {
            $parts[] = "m.classification_method IN ('rule', 'none') AND COALESCE(m.classification_confidence, 0) < " . Classifier::REVIEW_BELOW;
        }
        if (!empty($f['mine'])) {
            $parts[] = 'EXISTS (SELECT 1 FROM email_assignments ea2 WHERE ea2.email_id = m.id AND ea2.is_current = 1 AND ea2.assigned_to = ?)';
            $params[] = (int) $user['id'];
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $parts[] = '(m.subject LIKE ? OR m.from_email LIKE ? OR m.from_name LIKE ? OR m.body_preview LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        return [implode(' AND ', $parts), $params];
    }

    /** Count per category code (every active category present, zero if none). @return array<string, int> */
    public static function countByCategory(array $user, array $f): array
    {
        unset($f['category']);
        [$w, $p] = self::where($user, $f);
        $rows = Database::query(
            "SELECT c.code, COUNT(m.id) FROM email_categories c
             LEFT JOIN email_messages m ON m.category_id = c.id AND {$w}
             WHERE c.status = 'active' GROUP BY c.code, c.sort_order ORDER BY c.sort_order",
            $p
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    public static function count(array $user, array $f): int
    {
        [$w, $p] = self::where($user, $f);
        return (int) Database::value("SELECT COUNT(*) FROM email_messages m WHERE {$w}", $p);
    }

    /** Can this user see this email at all? */
    public static function visible(array $user, int $emailId): ?array
    {
        [$w, $p] = self::where($user, []);
        return Database::fetch("SELECT m.* FROM email_messages m WHERE m.id = ? AND {$w}", array_merge([$emailId], $p));
    }
}
