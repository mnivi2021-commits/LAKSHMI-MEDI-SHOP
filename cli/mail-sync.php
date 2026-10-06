<?php

declare(strict_types=1);

/*
 * Fetch new email for every active IMAP / API mailbox:  php cli/mail-sync.php
 * Schedule every 5 minutes (Windows Task Scheduler / cron). Manual-entry mailboxes are skipped.
 */

use App\Core\Database;
use App\Modules\Mail\MailService;
use App\Services\Mail\Providers;

require dirname(__DIR__) . '/bootstrap/app.php';

$failed = 0;
foreach (Database::fetchAll("SELECT * FROM email_accounts WHERE is_active = 1 AND provider <> 'manual'") as $account) {
    $why = Providers::for($account['provider'])->unavailableReason();
    if ($why !== null) {
        fwrite(STDERR, "{$account['email_address']}: skipped - {$why}\n");
        continue;
    }
    try {
        $r = MailService::sync($account);
        echo "{$account['email_address']}: {$r['received']} new\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "{$account['email_address']}: FAILED - {$e->getMessage()}\n");
    }
}
exit($failed > 0 ? 1 : 0);
