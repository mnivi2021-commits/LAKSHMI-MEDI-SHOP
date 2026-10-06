<?php

declare(strict_types=1);

namespace App\Modules\Sms;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Database;
use App\Core\DataScope;
use App\Core\Money;
use App\Core\Settings;
use App\Services\Sms\Gateways;
use App\Services\Sms\SmsGateway;
use DateTimeImmutable;
use Throwable;

/**
 * Recipients, queueing and sending. Every message goes through the queue
 * (sms_messages) and the gateway, and every gateway call is logged (sms_logs,
 * without API keys). Customers who opted out never receive campaign messages.
 */
final class SmsService
{
    public const TARGETS = ['customers' => 'Customers', 'leads' => 'Open leads', 'employees' => 'Employees'];

    public static function company(): string
    {
        return Settings::get('company', 'name', null) ?? (string) Config::get('app.name', 'Marketing CRM');
    }

    /**
     * Who would receive a campaign, within the user's data scope.
     *
     * @param array{branch_id?: ?int, employee_id?: ?int, overdue_only?: bool} $filter
     * @return array{recipients: list<array<string, mixed>>, excluded: array<string, int>}
     */
    public static function recipients(string $target, array $filter, array $user, DateTimeImmutable $today): array
    {
        $scope = DataScope::for($user);
        $params = [];
        $extra = '';
        foreach (['branch_id' => 'branch_id', 'employee_id' => 'employee_id'] as $k => $col) {
            if (!empty($filter[$k])) {
                $extra .= " AND x.{$col} = ?";
                $params[] = (int) $filter[$k];
            }
        }
        $company = self::company();
        $day = $today->format('Y-m-d');

        if ($target === 'customers') {
            [$s, $sp] = $scope->where('x.branch_id', 'x.employee_id');
            $overdue = !empty($filter['overdue_only']) ? ' AND ob.amount > 0' : '';
            $rows = Database::fetchAll(
                "SELECT x.id, x.name, x.mobile, x.sms_opt_out, e.name AS employee_name, ob.amount AS overdue, ob.first_invoice
                 FROM customers x
                 LEFT JOIN employees e ON e.id = x.employee_id
                 LEFT JOIN (SELECT b.customer_id, SUM(b.balance) AS amount,
                                   SUBSTRING_INDEX(GROUP_CONCAT(b.invoice_no ORDER BY b.invoice_date, b.invoice_id), ',', 1) AS first_invoice
                            FROM v_invoice_balances b WHERE b.balance > 0 AND b.due_date < ? GROUP BY b.customer_id) ob ON ob.customer_id = x.id
                 WHERE x.deleted_at IS NULL AND x.status = 'active' AND {$s}{$extra}{$overdue} ORDER BY x.name",
                array_merge([$day], $sp, $params)
            );
            $build = static fn (array $r): array => ['customer_id' => (int) $r['id'], 'lead_id' => null, 'employee_id' => null, 'vars' => [
                'name' => $r['name'], 'customer_name' => $r['name'], 'employee_name' => $r['employee_name'],
                'amount' => $r['overdue'] !== null ? self::amount(Money::fromDb($r['overdue'])) : null, 'invoice_no' => $r['first_invoice']]];
        } elseif ($target === 'leads') {
            [$s, $sp] = $scope->where('x.branch_id', 'x.employee_id');
            $rows = Database::fetchAll(
                "SELECT x.id, x.name, x.mobile, 0 AS sms_opt_out, e.name AS employee_name FROM leads x LEFT JOIN employees e ON e.id = x.employee_id
                 WHERE x.deleted_at IS NULL AND x.status NOT IN ('won', 'lost') AND {$s}{$extra} ORDER BY x.name",
                array_merge($sp, $params)
            );
            $build = static fn (array $r): array => ['customer_id' => null, 'lead_id' => (int) $r['id'], 'employee_id' => null,
                'vars' => ['name' => $r['name'], 'employee_name' => $r['employee_name']]];
        } else {
            [$s, $sp] = $scope->where('x.branch_id', 'x.id');
            $empFilter = str_replace('x.employee_id', 'x.id', $extra);
            $rows = Database::fetchAll(
                "SELECT x.id, x.name, x.mobile, 0 AS sms_opt_out FROM employees x WHERE x.deleted_at IS NULL AND x.status = 'active' AND {$s}{$empFilter} ORDER BY x.name",
                array_merge($sp, $params)
            );
            $build = static fn (array $r): array => ['customer_id' => null, 'lead_id' => null, 'employee_id' => (int) $r['id'],
                'vars' => ['name' => $r['name'], 'employee_name' => $r['name']]];
        }

        $out = [];
        $seen = [];
        $excluded = ['opted_out' => 0, 'no_mobile' => 0, 'duplicate' => 0];
        foreach ($rows as $r) {
            if ((int) $r['sms_opt_out'] === 1) {
                $excluded['opted_out']++;
                continue;
            }
            $mobile = SmsText::mobile($r['mobile']);
            if ($mobile === null) {
                $excluded['no_mobile']++;
                continue;
            }
            if (isset($seen[$mobile])) {
                $excluded['duplicate']++;
                continue;
            }
            $seen[$mobile] = true;
            $rec = $build($r);
            $rec['mobile'] = $mobile;
            $rec['name'] = $r['name'];
            $rec['vars'] += ['mobile' => $mobile, 'company' => $company, 'date' => $today->format('d-m-Y')];
            $out[] = $rec;
        }
        return ['recipients' => $out, 'excluded' => $excluded];
    }

    /**
     * Put messages in the queue (status queued). Returns the ids.
     *
     * @param list<array{mobile: string, name: ?string, customer_id: ?int, lead_id: ?int, employee_id: ?int, message: string}> $items
     * @return list<int>
     */
    public static function queue(array $items, ?int $templateId, ?int $campaignId, ?string $scheduledAt, int $userId, ?SmsGateway $gateway = null): array
    {
        $gateway ??= Gateways::current();
        $ids = [];
        $ins = Database::connection()->prepare(
            "INSERT INTO sms_messages (recipient_mobile, recipient_name, customer_id, lead_id, employee_id, template_id, campaign_id, message,
                                       encoding, segments, gateway, is_test, status, scheduled_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', ?, ?)"
        );
        foreach ($items as $it) {
            $m = SmsText::measure($it['message']);
            $ins->execute([$it['mobile'], $it['name'] !== null ? mb_substr($it['name'], 0, 150) : null, $it['customer_id'], $it['lead_id'], $it['employee_id'],
                $templateId, $campaignId, $it['message'], $m['encoding'], min(255, $m['segments']), $gateway->key(), $gateway->isTest() ? 1 : 0, $scheduledAt, $userId]);
            $ids[] = (int) Database::connection()->lastInsertId();
        }
        return $ids;
    }

    /**
     * Send queued messages that are due (or the given ids). Safe to run repeatedly:
     * each message is claimed with a status change before the gateway call.
     *
     * @param list<int>|null $onlyIds
     * @return array{sent: int, failed: int}
     */
    public static function dispatch(int $limit = 200, ?array $onlyIds = null, ?SmsGateway $gateway = null, ?DateTimeImmutable $now = null): array
    {
        $gateway ??= Gateways::current();
        $now ??= new DateTimeImmutable();
        $sql = "SELECT id FROM sms_messages WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= ?)";
        $params = [$now->format('Y-m-d H:i:s')];
        if ($onlyIds !== null) {
            if ($onlyIds === []) {
                return ['sent' => 0, 'failed' => 0];
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $params = array_merge($params, $onlyIds);
        }
        $ids = array_map('intval', array_column(Database::fetchAll($sql . " ORDER BY scheduled_at IS NULL DESC, scheduled_at, id LIMIT {$limit}", $params), 'id'));

        $sent = 0;
        $failed = 0;
        $campaigns = [];
        foreach ($ids as $id) {
            // Claim it: if another worker already took it, skip.
            $claimed = Database::query("UPDATE sms_messages SET status = 'pending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$id])->rowCount();
            if ($claimed === 0) {
                continue;
            }
            $msg = Database::fetch('SELECT * FROM sms_messages WHERE id = ?', [$id]);
            self::log($id, $gateway->key(), 'request', null, ['to' => self::mask($msg['recipient_mobile']), 'segments' => (int) $msg['segments'], 'encoding' => $msg['encoding']]);
            try {
                $r = $gateway->send($msg['recipient_mobile'], $msg['message'], ['id' => $id]);
            } catch (Throwable $e) {
                $r = ['ok' => false, 'provider_id' => null, 'status' => 'failed', 'error' => 'Gateway error: ' . mb_substr($e->getMessage(), 0, 200), 'http_status' => null, 'response' => []];
            }
            self::log($id, $gateway->key(), $r['ok'] ? 'response' : 'error', $r['http_status'], $r['response'] + ($r['error'] ? ['error' => $r['error']] : []));
            Database::query(
                "UPDATE sms_messages SET status = ?, provider_message_id = ?, sent_at = NOW(), delivered_at = IF(? = 'delivered', NOW(), NULL), error_message = ? WHERE id = ?",
                [$r['ok'] ? $r['status'] : 'failed', $r['provider_id'], $r['status'], $r['error'], $id]
            );
            $r['ok'] ? $sent++ : $failed++;
            if ($msg['campaign_id'] !== null) {
                $campaigns[(int) $msg['campaign_id']] = true;
            }
        }
        foreach (array_keys($campaigns) as $cid) {
            self::refreshCampaign($cid);
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Recount a campaign from its messages (counts are never incremented blindly). */
    public static function refreshCampaign(int $campaignId): void
    {
        $c = Database::fetch(
            "SELECT COUNT(*) AS total, SUM(status IN ('sent','delivered')) AS sent, SUM(status = 'delivered') AS delivered, SUM(status = 'failed') AS failed,
                    SUM(status IN ('queued','pending')) AS waiting FROM sms_messages WHERE campaign_id = ?",
            [$campaignId]
        );
        Database::query(
            "UPDATE sms_campaigns SET total_recipients = ?, sent_count = ?, delivered_count = ?, failed_count = ?,
                    started_at = COALESCE(started_at, IF(? > 0, NOW(), NULL)),
                    status = CASE WHEN status = 'cancelled' THEN status WHEN ? = 0 AND ? > 0 THEN 'completed' WHEN ? > 0 THEN 'processing' ELSE status END,
                    completed_at = IF(? = 0 AND ? > 0 AND status <> 'cancelled', COALESCE(completed_at, NOW()), completed_at)
             WHERE id = ?",
            [(int) $c['total'], (int) $c['sent'], (int) $c['delivered'], (int) $c['failed'],
             (int) $c['sent'] + (int) $c['failed'], (int) $c['waiting'], (int) $c['total'], (int) $c['sent'] + (int) $c['failed'],
             (int) $c['waiting'], (int) $c['total'], $campaignId]
        );
    }

    public static function cancelCampaign(int $campaignId, int $userId): int
    {
        $n = Database::query("UPDATE sms_messages SET status = 'cancelled' WHERE campaign_id = ? AND status = 'queued'", [$campaignId])->rowCount();
        Database::query("UPDATE sms_campaigns SET status = 'cancelled', updated_by = ? WHERE id = ? AND status IN ('draft', 'scheduled', 'processing')", [$userId, $campaignId]);
        self::refreshCampaign($campaignId);
        Audit::log('sms_campaign.cancelled', 'sms', $campaignId, null, ['messages_cancelled' => $n]);
        return $n;
    }

    private static function log(int $messageId, string $gateway, string $event, ?int $http, array $payload): void
    {
        Database::query('INSERT INTO sms_logs (sms_message_id, gateway, event, http_status, payload) VALUES (?, ?, ?, ?, ?)',
            [$messageId, $gateway, $event, $http, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    /** Indian grouping without the rupee sign (₹ would force costly Unicode SMS): 330164.5 -> "3,30,164.50". */
    public static function amount(int $paise): string
    {
        $rupees = (string) intdiv(abs($paise), 100);
        $last3 = substr($rupees, -3);
        $rest = substr($rupees, 0, -3);
        $grouped = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3 : $last3;
        return ($paise < 0 ? '-' : '') . $grouped . '.' . str_pad((string) (abs($paise) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 98xxxxx210 - logs never hold full numbers. */
    public static function mask(string $mobile): string
    {
        return strlen($mobile) >= 10 ? substr($mobile, 0, 2) . str_repeat('x', strlen($mobile) - 5) . substr($mobile, -3) : 'xxx';
    }
}
