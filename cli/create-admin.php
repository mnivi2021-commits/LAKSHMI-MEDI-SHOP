<?php

declare(strict_types=1);

/*
 * Create the first Admin Head on a clean install (no demo data):
 *
 *   php cli/create-admin.php                       asks for name, username and email
 *   php cli/create-admin.php --name="Murugan" --username=murugan --email=owner@example.com
 *
 * A random temporary password is printed ONCE; it must be changed at the first sign-in.
 * Refuses when an active Admin Head already exists (add more users from Access -> Users).
 */

use App\Core\Audit;
use App\Core\Database;
use App\Core\PasswordPolicy;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['name:', 'username:', 'email:']);

$role = Database::value("SELECT id FROM roles WHERE slug = 'admin_head'");
if (!$role) {
    fwrite(STDERR, "The Admin Head role is missing. Run php cli/install.php first.\n");
    exit(1);
}
if (Database::value("SELECT 1 FROM users WHERE role_id = ? AND status = 'active' AND deleted_at IS NULL", [$role])) {
    fwrite(STDERR, "An active Admin Head already exists. Sign in and add users from Access -> Users.\n");
    exit(1);
}

$ask = static function (string $label, ?string $given, callable $check): string {
    $value = $given;
    while (true) {
        if ($value === null) {
            echo $label . ': ';
            $value = trim((string) fgets(STDIN));
        }
        $problem = $check($value);
        if ($problem === null) {
            return $value;
        }
        echo "  {$problem}\n";
        $value = null;
    }
};

$name = $ask('Full name', $opts['name'] ?? null, static fn ($v) => $v !== '' && mb_strlen($v) <= 100 ? null : 'Enter a name (up to 100 characters).');
$username = $ask('Username (letters, digits, . _ -)', $opts['username'] ?? null, static function ($v) {
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v)) {
        return '3-50 letters, digits, dot, underscore or hyphen.';
    }
    return Database::value('SELECT 1 FROM users WHERE username = ?', [$v]) ? 'That username is taken.' : null;
});
$email = $ask('Email', $opts['email'] ?? null, static function ($v) {
    if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
        return 'Enter a valid email address.';
    }
    return Database::value('SELECT 1 FROM users WHERE email = ?', [strtolower($v)]) ? 'That email is already used.' : null;
});

$temp = PasswordPolicy::generateTemporary();
Database::query(
    'INSERT INTO users (role_id, name, username, email, password_hash, must_change_password) VALUES (?, ?, ?, ?, ?, 1)',
    [$role, $name, $username, strtolower($email), password_hash($temp, PASSWORD_DEFAULT)]
);
$id = (int) Database::connection()->lastInsertId();
Audit::log('user.created', 'users', $id, null, ['username' => $username, 'role' => 'admin_head', 'via' => 'cli/create-admin.php'],
    ['id' => null, 'name' => 'system', 'role_slug' => null]);

echo "\nAdmin Head created.\n";
echo "  Username           : {$username}\n";
echo "  Temporary password : {$temp}\n";
echo "Sign in and choose your own password. This temporary password is not shown again.\n";
