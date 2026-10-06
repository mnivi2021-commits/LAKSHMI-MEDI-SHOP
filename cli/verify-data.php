<?php

declare(strict_types=1);

/*
 * php cli/verify-data.php   - run every cross-table integrity rule.
 * Exit code 1 if any "error" rule fails (usable in scripts / after imports).
 */

use App\Services\DataIntegrity;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$errors = 0;
$warnings = 0;

foreach ((new DataIntegrity())->run() as $r) {
    if ($r['count'] === 0) {
        printf("[PASS] %s\n", $r['title']);
        continue;
    }
    $r['severity'] === 'error' ? $errors++ : $warnings++;
    printf("[%s] %s - %d record(s), ids: %s\n",
        $r['severity'] === 'error' ? 'FAIL' : 'WARN',
        $r['title'], $r['count'], implode(', ', $r['sample_ids']) . ($r['count'] > 10 ? ' ...' : ''));
}

echo PHP_EOL . "{$errors} error rule(s), {$warnings} warning rule(s)" . PHP_EOL;
exit($errors > 0 ? 1 : 0);
