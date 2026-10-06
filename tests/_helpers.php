<?php

declare(strict_types=1);

/*
 * Shared helpers for the DB-backed PHP test files. Include AFTER bootstrap.
 *   check($name, $expected, $actual);  ...  exit(test_summary());
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['__passed'] = 0;
$GLOBALS['__failed'] = 0;

function check(string $name, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        $GLOBALS['__passed']++;
        return;
    }
    $GLOBALS['__failed']++;
    echo "FAIL  {$name}\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true) . "\n";
}

function test_summary(): int
{
    echo PHP_EOL . $GLOBALS['__passed'] . ' passed, ' . $GLOBALS['__failed'] . ' failed' . PHP_EOL;
    return $GLOBALS['__failed'] > 0 ? 1 : 0;
}

/** Load a seeded user by username (with role details). */
function seeded_user(string $username): array
{
    $id = (int) \App\Core\Database::value('SELECT id FROM users WHERE username = ?', [$username]);
    return \App\Core\Auth::loadUser($id);
}
