<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Password rules for every account. Length matters more than symbol tricks,
 * so the policy is: 10+ characters, letters and digits, not the username,
 * not a well-known password, not the same as the current one.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;
    public const MAX_LENGTH = 72; // bcrypt ignores bytes beyond 72

    private const COMMON = [
        'password', 'password1', 'password123', 'password@123', 'passw0rd', 'qwerty123', 'qwertyuiop',
        '1234567890', '123456789a', 'admin@123', 'admin12345', 'admin@1234', 'welcome@123', 'welcome123',
        'changeme123', 'iloveyou123', 'abcd@12345', 'india@123', 'india12345', 'test@12345',
        'admin@2026', 'coord@2026', 'sales@2026', // seeded demo passwords
    ];

    /** @return list<string> human-readable problems; empty = acceptable */
    public static function validate(string $password, string $username = '', string $email = ''): array
    {
        $errors = [];
        $len = mb_strlen($password);

        if ($len < self::MIN_LENGTH) {
            $errors[] = 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if (strlen($password) > self::MAX_LENGTH) {
            $errors[] = 'Use at most ' . self::MAX_LENGTH . ' characters.';
        }
        if (!preg_match('/\pL/u', $password) || !preg_match('/\d/', $password)) {
            $errors[] = 'Include at least one letter and one number.';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            $errors[] = 'This password is too common. Choose something less predictable.';
        }

        $lower = mb_strtolower($password);
        foreach ([$username, strstr($email, '@', true) ?: ''] as $personal) {
            if (mb_strlen($personal) >= 3 && str_contains($lower, mb_strtolower($personal))) {
                $errors[] = 'Do not include your username or email name.';
                break;
            }
        }
        if ($len > 0 && count(array_unique(mb_str_split($password))) < 4) {
            $errors[] = 'Use more varied characters.';
        }

        return $errors;
    }
}
