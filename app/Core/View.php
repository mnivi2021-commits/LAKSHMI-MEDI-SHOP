<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Plain-PHP templates in app/Views. Always escape output with e(). */
final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = []): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#i', $view) || str_contains($view, '..')) {
            throw new RuntimeException('Invalid view name');
        }

        $file = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: {$view}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
