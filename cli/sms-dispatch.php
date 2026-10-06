<?php

declare(strict_types=1);

/*
 * Send queued / scheduled SMS that are due:  php cli/sms-dispatch.php
 * Schedule every minute (Windows Task Scheduler / cron). Safe to overlap:
 * each message is claimed before it is sent, so it is never sent twice.
 */

use App\Modules\Sms\SmsService;

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $r = SmsService::dispatch(1000);
    echo "sent {$r['sent']}, failed {$r['failed']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'SMS dispatch failed: ' . $e->getMessage() . "\n");
    exit(1);
}
