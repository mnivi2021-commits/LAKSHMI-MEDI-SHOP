<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Gapless, concurrency-safe document numbers (CUS-00001, LD-00001, ...).
 * Locks the sequence row with SELECT ... FOR UPDATE, so two concurrent
 * requests can never be handed the same number.
 */
final class NumberSequence
{
    /** Must be called inside Database::transaction() so the row lock is held until commit. */
    public static function next(string $name): string
    {
        $pdo = Database::connection();
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('NumberSequence::next() must run inside Database::transaction()');
        }

        $row = Database::fetch('SELECT prefix, next_number, padding FROM number_sequences WHERE name = ? FOR UPDATE', [$name]);
        if ($row === null) {
            throw new RuntimeException("Unknown number sequence: {$name}");
        }

        $value = $row['prefix'] . str_pad((string) $row['next_number'], (int) $row['padding'], '0', STR_PAD_LEFT);
        Database::query('UPDATE number_sequences SET next_number = next_number + 1 WHERE name = ?', [$name]);
        return $value;
    }
}
