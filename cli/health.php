<?php

declare(strict_types=1);

/* php cli/health.php  - same checks as /health, printed to the terminal. Exit 1 on failure. */

use App\Core\HealthCheck;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$report = (new HealthCheck())->run();

foreach ($report['checks'] as $c) {
    printf("[%-4s] %-9s %-26s %s\n", strtoupper($c['status']), $c['group'], $c['name'], $c['message']);
}

$fy = $report['financial_year'];
echo PHP_EOL;
echo "Financial year        : {$fy['label']} ({$fy['range']})" . PHP_EOL;
echo "FY to previous day    : {$fy['fy_to_previous_day']}" . PHP_EOL;
echo "Month to previous day : {$fy['month_to_previous_day']}" . PHP_EOL;
echo "Today                 : {$fy['today']}" . PHP_EOL;
echo PHP_EOL . 'OVERALL: ' . strtoupper($report['status']) . PHP_EOL;

exit($report['status'] === 'fail' ? 1 : 0);
