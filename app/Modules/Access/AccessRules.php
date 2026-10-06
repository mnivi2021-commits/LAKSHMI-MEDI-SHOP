<?php

declare(strict_types=1);

namespace App\Modules\Access;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Gate;

/**
 * Guards shared by user and role management:
 *  - nobody can hand out (or manage someone with) permissions they do not hold
 *  - only an Admin Head can create / edit / assign the Admin Head role
 *  - the last active Admin Head can never be disabled, deleted or demoted
 */
final class AccessRules
{
    /** Roles the current user may assign to someone. @return list<array<string, mixed>> */
    public static function assignableRoles(): array
    {
        $roles = Database::fetchAll("SELECT id, name, slug, data_scope, is_system FROM roles WHERE status = 'active' ORDER BY id");
        return array_values(array_filter($roles, static fn (array $r): bool => self::canAssignRole((int) $r['id'], $r['slug'])));
    }

    public static function canAssignRole(int $roleId, ?string $slug = null): bool
    {
        $slug ??= (string) Database::value('SELECT slug FROM roles WHERE id = ?', [$roleId]);
        if ($slug === Gate::SUPER_ROLE) {
            return Gate::isSuper();
        }
        return Gate::canGrantAll(Gate::rolePermissions($roleId));
    }

    /**
     * May the current user manage (edit / disable / reset / delete) this user?
     *
     * @param array<string, mixed> $target row with id, role_id, role_slug
     */
    public static function canManageUser(array $target): bool
    {
        if (Gate::isSuper()) {
            return true;
        }
        if ($target['role_slug'] === Gate::SUPER_ROLE) {
            return false;
        }
        $grants = Database::query(
            "SELECT p.slug FROM user_permissions up JOIN permissions p ON p.id = up.permission_id WHERE up.user_id = ? AND up.effect = 'grant'",
            [$target['id']]
        )->fetchAll(\PDO::FETCH_COLUMN);
        return Gate::canGrantAll(array_merge(Gate::rolePermissions((int) $target['role_id']), $grants));
    }

    public static function isLastActiveSuper(int $userId): bool
    {
        $supers = Database::query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.slug = ? AND u.status = 'active' AND u.deleted_at IS NULL",
            [Gate::SUPER_ROLE]
        )->fetchAll(\PDO::FETCH_COLUMN);
        return count($supers) === 1 && (int) $supers[0] === $userId;
    }

    public static function isSelf(int $userId): bool
    {
        return Auth::id() === $userId;
    }

    /**
     * Permission catalogue grouped by module, in display order.
     *
     * @return array<string, array{label: string, items: array<string, array{id: int, slug: string, critical: bool}>}>
     */
    public static function permissionCatalogue(): array
    {
        $rows = Database::fetchAll('SELECT id, module, action, slug, description, is_critical FROM permissions ORDER BY sort_order, id');
        $out = [];
        foreach ($rows as $r) {
            // description is "View Branch Details" -> module label "Branch Details"
            $label = preg_replace('/^\S+\s+/', '', (string) $r['description']);
            $out[$r['module']]['label'] ??= $label;
            $out[$r['module']]['items'][$r['action']] = [
                'id'       => (int) $r['id'],
                'slug'     => $r['slug'],
                'critical' => (bool) $r['is_critical'],
            ];
        }
        return $out;
    }

    /** Ordered list of every action column used by the matrix. @return list<string> */
    public static function actionColumns(): array
    {
        $preferred = ['view', 'add', 'edit', 'delete', 'import', 'export', 'send', 'bulk', 'manage'];
        $present = Database::query('SELECT DISTINCT action FROM permissions')->fetchAll(\PDO::FETCH_COLUMN);
        $ordered = array_values(array_intersect($preferred, $present));
        return array_merge($ordered, array_values(array_diff($present, $preferred)));
    }

    /**
     * Turn submitted permission ids into slugs, ignoring anything unknown.
     *
     * @param mixed $submitted
     * @return array<int, string> id => slug
     */
    public static function sanitisePermissionIds(mixed $submitted): array
    {
        if (!is_array($submitted) || $submitted === []) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $submitted), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $rows = Database::fetchAll('SELECT id, slug FROM permissions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        return array_column($rows, 'slug', 'id');
    }
}
