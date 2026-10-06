<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Logger;
use App\Core\NumberSequence;
use App\Services\Mail\IncomingMail;
use App\Services\Mail\Providers;
use RuntimeException;
use Throwable;

/** Everything that changes an email: receive, re-categorise, assign, status, link, create lead, sync. */
final class MailService
{
    public const STATUSES = ['new' => 'New', 'open' => 'Open', 'in_progress' => 'In progress', 'closed' => 'Closed', 'ignored' => 'Ignored'];

    /** Store one incoming email (classified and matched). Returns the id, or null if it was already stored. */
    public static function receive(int $accountId, IncomingMail $m, ?int $userId = null, ?Classifier $classifier = null): ?int
    {
        if (Database::value('SELECT 1 FROM email_messages WHERE account_id = ? AND provider_message_id = ?', [$accountId, $m->providerMessageId])) {
            return null;
        }
        if ($m->internetMessageId !== null && Database::value('SELECT 1 FROM email_messages WHERE account_id = ? AND internet_message_id = ?', [$accountId, $m->internetMessageId])) {
            return null;
        }
        $result = ($classifier ?? new Classifier())->classify($m->subject, $m->bodyPreview);
        $match = self::match($m->fromEmail, $accountId);

        return Database::transaction(static function () use ($accountId, $m, $userId, $result, $match): int {
            Database::query(
                "INSERT INTO email_messages (account_id, provider_message_id, internet_message_id, from_email, from_name, to_recipients, subject, body_preview,
                                             received_at, has_attachments, category_id, auto_category_id, classification_method, classification_confidence,
                                             customer_id, lead_id, branch_id, employee_id, status, followup_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', 'none')",
                [$accountId, $m->providerMessageId, $m->internetMessageId, strtolower($m->fromEmail), $m->fromName, $m->toRecipients, $m->subject,
                 $m->bodyPreview, $m->receivedAt, $m->hasAttachments ? 1 : 0, $result['category_id'], $result['category_id'], $result['method'],
                 $result['confidence'], $match['customer_id'], $match['lead_id'], $match['branch_id'], $match['employee_id']]
            );
            $id = (int) Database::connection()->lastInsertId();
            foreach ($m->attachments as $a) {
                Database::query('INSERT INTO email_attachments (email_id, original_name, mime_type, size_bytes) VALUES (?, ?, ?, ?)',
                    [$id, mb_substr($a['name'], 0, 255), $a['mime'] !== null ? mb_substr($a['mime'], 0, 100) : null, $a['size']]);
            }
            self::activity($id, $userId, 'received', null, null, $match['note']);
            self::activity($id, null, 'classified', null, self::code($result['category_id']),
                $result['method'] === 'rule' ? 'Rules matched: ' . implode(', ', array_slice($result['matched'], 0, 6)) . " ({$result['confidence']}%)" : 'No rule matched');
            return $id;
        });
    }

    /**
     * Who is this from? Exact customer email, else a lead with that email, else a customer with
     * the same company domain (only when exactly one customer uses it and it is not a public mail domain).
     *
     * @return array{customer_id: ?int, lead_id: ?int, branch_id: ?int, employee_id: ?int, note: ?string}
     */
    public static function match(string $fromEmail, int $accountId): array
    {
        $from = strtolower(trim($fromEmail));
        $c = Database::fetch("SELECT id, customer_code, branch_id, employee_id FROM customers WHERE LOWER(email) = ? AND deleted_at IS NULL ORDER BY status = 'active' DESC LIMIT 1", [$from]);
        if ($c !== null) {
            return ['customer_id' => (int) $c['id'], 'lead_id' => null, 'branch_id' => (int) $c['branch_id'], 'employee_id' => $c['employee_id'] !== null ? (int) $c['employee_id'] : null,
                    'note' => "Matched customer {$c['customer_code']} by email address."];
        }
        $l = Database::fetch("SELECT id, lead_number, branch_id, employee_id FROM leads WHERE LOWER(email) = ? AND deleted_at IS NULL ORDER BY status NOT IN ('won','lost') DESC, id DESC LIMIT 1", [$from]);
        if ($l !== null) {
            return ['customer_id' => null, 'lead_id' => (int) $l['id'], 'branch_id' => (int) $l['branch_id'], 'employee_id' => $l['employee_id'] !== null ? (int) $l['employee_id'] : null,
                    'note' => "Matched lead {$l['lead_number']} by email address."];
        }
        $domain = substr(strrchr($from, '@') ?: '', 1);
        $public = ['gmail.com', 'yahoo.com', 'yahoo.co.in', 'hotmail.com', 'outlook.com', 'live.com', 'rediffmail.com', 'icloud.com', 'protonmail.com', 'aol.com', 'zoho.com', 'ymail.com'];
        if ($domain !== '' && !in_array($domain, $public, true)) {
            $rows = Database::fetchAll("SELECT id, customer_code, branch_id, employee_id FROM customers WHERE LOWER(email) LIKE ? AND deleted_at IS NULL LIMIT 2", ['%@' . addcslashes($domain, '%_\\')]);
            if (count($rows) === 1) {
                $c = $rows[0];
                return ['customer_id' => (int) $c['id'], 'lead_id' => null, 'branch_id' => (int) $c['branch_id'], 'employee_id' => $c['employee_id'] !== null ? (int) $c['employee_id'] : null,
                        'note' => "Matched customer {$c['customer_code']} by company domain {$domain}."];
            }
        }
        $branch = Database::value('SELECT branch_id FROM email_accounts WHERE id = ?', [$accountId]);
        return ['customer_id' => null, 'lead_id' => null, 'branch_id' => $branch ? (int) $branch : null, 'employee_id' => null, 'note' => 'Sender not found in customers or leads.'];
    }

    public static function recategorise(array $email, int $categoryId, int $userId): void
    {
        if ((int) $email['category_id'] === $categoryId) {
            return;
        }
        Database::query(
            "UPDATE email_messages SET category_id = ?, classification_method = 'manual', classification_confidence = NULL,
                    category_corrected_by = ?, category_corrected_at = NOW() WHERE id = ?",
            [$categoryId, $userId, $email['id']]
        );
        self::activity((int) $email['id'], $userId, 'recategorised', self::code($email['category_id']), self::code($categoryId));
        Audit::log('email.recategorised', 'mail', (int) $email['id'], ['category' => self::code($email['category_id'])], ['category' => self::code($categoryId)]);
    }

    /** Assign to a CRM user; the email follows that user's employee for dashboard counts. */
    public static function assign(array $email, array $assignee, int $byUserId, ?string $note): void
    {
        Database::transaction(static function () use ($email, $assignee, $byUserId, $note): void {
            Database::query('UPDATE email_assignments SET is_current = 0 WHERE email_id = ? AND is_current = 1', [$email['id']]);
            Database::query('INSERT INTO email_assignments (email_id, assigned_to, assigned_by, note) VALUES (?, ?, ?, ?)', [$email['id'], $assignee['id'], $byUserId, $note]);
            $employeeId = $assignee['employee_id'] !== null ? (int) $assignee['employee_id'] : null;
            $branchId = $employeeId !== null ? Database::value('SELECT branch_id FROM employees WHERE id = ?', [$employeeId]) : null;
            Database::query(
                "UPDATE email_messages SET employee_id = COALESCE(?, employee_id), branch_id = COALESCE(branch_id, ?),
                        status = IF(status = 'new', 'open', status) WHERE id = ?",
                [$employeeId, $branchId, $email['id']]
            );
            self::activity((int) $email['id'], $byUserId, 'assigned', null, $assignee['name'], $note);
        });
        Audit::log('email.assigned', 'mail', (int) $email['id'], null, ['assigned_to' => (int) $assignee['id']]);
    }

    public static function setStatus(array $email, string $status, ?string $followup, int $userId): void
    {
        $followup ??= $email['followup_status'];
        Database::query('UPDATE email_messages SET status = ?, followup_status = ? WHERE id = ?', [$status, $followup, $email['id']]);
        if ($status !== $email['status']) {
            self::activity((int) $email['id'], $userId, 'status_changed', $email['status'], $status);
        }
        if ($followup !== $email['followup_status']) {
            self::activity((int) $email['id'], $userId, 'followup_changed', $email['followup_status'], $followup);
        }
    }

    public static function linkCustomer(array $email, array $customer, int $userId): void
    {
        Database::query('UPDATE email_messages SET customer_id = ?, branch_id = ?, employee_id = COALESCE(employee_id, ?) WHERE id = ?',
            [$customer['id'], $customer['branch_id'], $customer['employee_id'], $email['id']]);
        self::activity((int) $email['id'], $userId, 'customer_linked', null, $customer['customer_code']);
    }

    /** New lead from an enquiry email (source = Email). Returns the lead id. */
    public static function createLead(array $email, int $branchId, ?int $employeeId, int $userId): int
    {
        $id = Database::transaction(static function () use ($email, $branchId, $employeeId, $userId): int {
            $source = Database::value("SELECT id FROM lead_sources WHERE name = 'Email'");
            $name = trim((string) ($email['from_name'] ?? '')) ?: strstr($email['from_email'], '@', true);
            Database::query(
                "INSERT INTO leads (lead_number, name, email, source_id, branch_id, employee_id, status, priority, remarks, email_message_id, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'new', 'medium', ?, ?, ?, ?)",
                [NumberSequence::next('lead'), mb_substr($name, 0, 150), $email['from_email'], $source ?: null, $branchId, $employeeId,
                 mb_substr('From email: ' . ($email['subject'] ?? '') . "\n" . ($email['body_preview'] ?? ''), 0, 2000), $email['id'], $userId, $userId]
            );
            $leadId = (int) Database::connection()->lastInsertId();
            Database::query("UPDATE email_messages SET lead_id = ?, branch_id = ?, employee_id = COALESCE(?, employee_id), status = IF(status IN ('new','open'), 'in_progress', status) WHERE id = ?",
                [$leadId, $branchId, $employeeId, $email['id']]);
            self::activity((int) $email['id'], $userId, 'lead_created', null, (string) $leadId);
            return $leadId;
        });
        Audit::log('lead.created', 'leads', $id, null, ['source' => 'email', 'email_id' => (int) $email['id']]);
        return $id;
    }

    /** Fetch new mail for one account. @return array{received: int, skipped: int} */
    public static function sync(array $account, int $limit = 200): array
    {
        $provider = Providers::for($account['provider']);
        try {
            $res = $provider->fetch($account, $account['sync_cursor'], $limit);
        } catch (Throwable $e) {
            Database::query('UPDATE email_accounts SET last_sync_error = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $account['id']]);
            Logger::warning('Mail sync failed', ['account' => (int) $account['id'], 'error' => $e->getMessage()]);
            throw new RuntimeException($e->getMessage());
        }
        $classifier = new Classifier();
        $received = 0;
        foreach ($res['messages'] as $m) {
            if (self::receive((int) $account['id'], $m, null, $classifier) !== null) {
                $received++;
            }
        }
        Database::query('UPDATE email_accounts SET sync_cursor = ?, last_synced_at = NOW(), last_sync_error = NULL WHERE id = ?', [$res['cursor'], $account['id']]);
        return ['received' => $received, 'skipped' => count($res['messages']) - $received];
    }

    /** Re-run the rules on emails nobody has corrected by hand. Returns how many changed category. */
    public static function reclassify(int $userId): int
    {
        $classifier = new Classifier();
        $changed = 0;
        foreach (Database::fetchAll("SELECT id, subject, body_preview, category_id FROM email_messages WHERE classification_method <> 'manual'") as $e) {
            $r = $classifier->classify($e['subject'], $e['body_preview']);
            Database::query('UPDATE email_messages SET category_id = ?, auto_category_id = ?, classification_method = ?, classification_confidence = ? WHERE id = ?',
                [$r['category_id'], $r['category_id'], $r['method'], $r['confidence'], $e['id']]);
            if ((int) $e['category_id'] !== (int) $r['category_id']) {
                self::activity((int) $e['id'], $userId, 'reclassified', self::code($e['category_id']), self::code($r['category_id']));
                $changed++;
            }
        }
        return $changed;
    }

    public static function activity(int $emailId, ?int $userId, string $action, ?string $old, ?string $new, ?string $note = null): void
    {
        Database::query('INSERT INTO email_activity (email_id, user_id, action, old_value, new_value, note) VALUES (?, ?, ?, ?, ?, ?)',
            [$emailId, $userId, $action, $old !== null ? mb_substr($old, 0, 255) : null, $new !== null ? mb_substr($new, 0, 255) : null, $note !== null ? mb_substr($note, 0, 500) : null]);
    }

    private static function code(mixed $categoryId): ?string
    {
        return $categoryId === null ? null : (Database::value('SELECT code FROM email_categories WHERE id = ?', [(int) $categoryId]) ?: null);
    }
}
