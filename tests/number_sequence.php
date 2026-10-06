<?php

declare(strict_types=1);

/*
 * NumberSequence - accuracy tests:  php tests/number_sequence.php
 * Seeded DB; rolled back.
 */

use App\Core\Database;
use App\Core\NumberSequence;

require dirname(__DIR__) . '/bootstrap/app.php';
require __DIR__ . '/_helpers.php';

$pdo = Database::connection();

// Checked before the test's own wrapping transaction starts (inTransaction() would
// otherwise read as true because of that wrapper, not because of this call).
try {
    NumberSequence::next('customer');
    check('requires a transaction', true, false);
} catch (RuntimeException $e) {
    check('requires a transaction', true, str_contains($e->getMessage(), 'transaction'));
}

$pdo->beginTransaction();

try {
    $before = Database::value("SELECT next_number FROM number_sequences WHERE name = 'customer'");
    check('seeded next_number is 13', 13, (int) $before);

    $code = Database::transaction(static fn () => NumberSequence::next('customer'));
    check('first allocation', 'CUS-00013', $code);
    $code2 = Database::transaction(static fn () => NumberSequence::next('customer'));
    check('second allocation increments', 'CUS-00014', $code2);
    check('row advanced by 2', 15, (int) Database::value("SELECT next_number FROM number_sequences WHERE name = 'customer'"));

    $leadCode = Database::transaction(static fn () => NumberSequence::next('lead'));
    check('different sequence, different prefix', 'LD-00013', $leadCode);

    try {
        Database::transaction(static fn () => NumberSequence::next('not_a_real_sequence'));
        check('unknown sequence rejected', true, false);
    } catch (RuntimeException $e) {
        check('unknown sequence rejected', true, str_contains($e->getMessage(), 'Unknown number sequence'));
    }
} finally {
    $pdo->rollBack();
}

exit(test_summary());
