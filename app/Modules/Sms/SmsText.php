<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Message text helpers: placeholders and SMS length.
 *
 * Length follows the GSM 03.38 rules operators bill by: plain GSM-7 text is 160
 * characters in one SMS or 153 per part; any other character (₹, Tamil, emoji)
 * makes the whole message Unicode: 70 / 67 per part. ^ { } [ ] ~ | \ € count twice.
 */
final class SmsText
{
    public const PLACEHOLDERS = [
        'name' => 'Recipient name', 'customer_name' => 'Customer name', 'company' => 'Your company name',
        'employee_name' => 'Sales employee', 'mobile' => 'Recipient mobile', 'date' => 'Today (DD-MM-YYYY)',
        'amount' => 'Amount', 'invoice_no' => 'Invoice no', 'order_no' => 'Order no', 'delivery_date' => 'Delivery date',
        'enquiry_no' => 'Enquiry no', 'products' => 'Products (enquiry)', 'po_ref' => 'Customer PO reference',
        'due_date' => 'Oldest due date', 'bill_count' => 'Number of bills due',
    ];
    public const MAX_SEGMENTS = 6;

    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    private const GSM_EXT = '^{}\\[~]|€';

    /** @return array{encoding: string, length: int, segments: int, per_segment: int} */
    public static function measure(string $text): array
    {
        $unicode = false;
        $len = 0;
        foreach (mb_str_split($text) as $ch) {
            if (mb_strpos(self::GSM, $ch) !== false) {
                $len++;
            } elseif (mb_strpos(self::GSM_EXT, $ch) !== false) {
                $len += 2;
            } else {
                $unicode = true;
                break;
            }
        }
        if ($unicode) {
            $len = count(mb_str_split($text));       // UCS-2 units (BMP); good enough for billing estimates
            return ['encoding' => 'unicode', 'length' => $len, 'segments' => $len <= 70 ? 1 : (int) ceil($len / 67), 'per_segment' => $len <= 70 ? 70 : 67];
        }
        return ['encoding' => 'gsm7', 'length' => $len, 'segments' => $len <= 160 ? 1 : (int) ceil($len / 153), 'per_segment' => $len <= 160 ? 160 : 153];
    }

    /** @return list<string> placeholder names used in the text */
    public static function placeholders(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $body, $m);
        return array_values(array_unique($m[1]));
    }

    /** @return list<string> placeholders that are not known */
    public static function unknownPlaceholders(string $body): array
    {
        return array_values(array_diff(self::placeholders($body), array_keys(self::PLACEHOLDERS)));
    }

    /**
     * Fill placeholders (plain text substitution - nothing is evaluated).
     *
     * @param array<string, ?string> $vars
     * @return array{0: string, 1: list<string>} [text, placeholders left without a value]
     */
    public static function render(string $body, array $vars): array
    {
        $missing = [];
        $out = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars, &$missing): string {
            $v = $vars[$m[1]] ?? null;
            if ($v === null || $v === '') {
                $missing[] = $m[1];
                return $m[0];
            }
            return preg_replace('/[\r\n]+/', ' ', $v);
        }, $body);
        return [trim($out), array_values(array_unique($missing))];
    }

    /** Indian mobile -> 10 digits, or null. */
    public static function mobile(?string $raw): ?string
    {
        $v = preg_replace('/[\s\-()]/', '', (string) $raw);
        return preg_match('/^(\+91|91|0)?([6-9]\d{9})$/', $v, $m) ? $m[2] : null;
    }
}
