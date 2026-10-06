<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Permission checks. Effective permissions =
 *     role permissions  +  user "grant" overrides  -  user "deny" overrides
 * Admin Head always holds every permission (cannot be locked out by a mistake
 * in the matrix). Hiding a button is never enough: every route and every
 * action re-checks here on the server.
 */
final class Gate
{
    public const SUPER_ROLE = 'admin_head';

    /** @var array<int, array<string, true>> user id => set of permission slugs */
    private static array $cache = [];

    /** @return list<string> sorted permission slugs */
    public static function permissionsFor(array $user): array
    {
        return array_keys(self::set($user));
    }

    public static function allows(string $slug, ?array $user = null): bool
    {
        $user ??= Auth::user();
        return $user !== null && isset(self::set($user)[$slug]);
    }

    /** True if the user holds at least one permission of a module (e.g. to show its menu item). */
    public static function allowsAny(array $slugs, ?array $user = null): bool
    {
        foreach ($slugs as $slug) {
            if (self::allows($slug, $user)) {
                return true;
            }
        }
        return false;
    }

    /** Responds 403 and returns false when not allowed. */
    public static function authorize(string $slug): bool
    {
        if (self::allows($slug)) {
            return true;
        }
        Logger::warning('Permission denied', ['permission' => $slug, 'user_id' => Auth::id(), 'path' => Request::path()]);
        Response::error(403, 'You do not have permission to do this. Ask the Admin Head if you need access.');
        return false;
    }

    public static function isSuper(?array $user = null): bool
    {
        $user ??= Auth::user();
        return ($user['role_slug'] ?? null) === self::SUPER_ROLE;
    }

    /**
     * Permission slugs a role grants (Admin Head = all).
     *
     * @return list<string>
     */
    public static function rolePermissions(int $roleId): array
    {
        $slug = Database::value('SELECT slug FROM roles WHERE id = ?', [$roleId]);
        if ($slug === self::SUPER_ROLE) {
            return Database::query('SELECT slug FROM permissions ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN);
        }
        return Database::query(
            'SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug',
            [$roleId]
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Privilege-escalation guard: may the actor hand out this set of permissions?
     * Only if the actor holds every one of them.
     *
     * @param list<string> $slugs
     */
    public static function canGrantAll(array $slugs, ?array $actor = null): bool
    {
        $actor ??= Auth::user();
        if ($actor === null) {
            return false;
        }
        $held = self::set($actor);
        foreach ($slugs as $slug) {
            if (!isset($held[$slug])) {
                return false;
            }
        }
        return true;
    }

    /** Call after changing roles/permissions so the next check reloads. */
    public static function forget(?int $userId = null): void
    {
        if ($userId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$userId]);
        }
    }

    /** @return array<string, true> */
    private static function set(array $user): array
    {
        $id = (int) $user['id'];
        if (isset(self::$cache[$id])) {
            return self::$cache[$id];
        }

        if (($user['role_slug'] ?? null) === self::SUPER_ROLE) {
            $slugs = Database::query('SELECT slug FROM permissions')->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $slugs = Database::query(
                "SELECT p.slug
                 FROM permissions p
                 WHERE (
                        p.id IN (SELECT rp.permission_id FROM role_permissions rp WHERE rp.role_id = ?)
                        AND p.id NOT IN (SELECT up.permission_id FROM user_permissions up WHERE up.user_id = ? AND up.effect = 'deny')
                       )
                    OR p.id IN (SELECT up.permission_id FROM user_permissions up WHERE up.user_id = ? AND up.effect = 'grant')",
                [$user['role_id'], $id, $id]
            )->fetchAll(\PDO::FETCH_COLUMN);
        }

        sort($slugs);
        return self::$cache[$id] = array_fill_keys($slugs, true);
    }
}
