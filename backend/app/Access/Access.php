<?php

namespace App\Access;

use App\Auth\AdminAudit;
use App\Models\User;
use App\Settings\FeatureFlags;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Roles and permissions of the admin team (decision 2.7), kept in the database (roles, permissions, role_permissions,
 * user_roles). Founded now, with one role in use: «admin», which may do everything. Other roles grant nothing while the
 * `multi_admin` switch is off, and cannot even be given to anybody; the database itself refuses a role to a customer.
 *
 * Only a live, active staff account holds any permission. The last active admin cannot lose the admin role (nobody would
 * be left to give it back). Every role given or taken is written to the audit log.
 */
final class Access
{
    public const ADMIN = 'admin';

    public function can(?User $user, string $permission): bool
    {
        if ($user === null || ! $user->isStaff() || ! $user->is_active || $user->deleted_at !== null || ! PermissionCatalog::exists($permission)) {
            return false;
        }
        if ($this->hasRole($user->id, self::ADMIN)) {
            return true;
        }

        return FeatureFlags::on('multi_admin') && DB::table('user_roles as ur')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $user->id)->where('p.code', $permission)->exists();
    }

    public function hasRole(int $userId, string $role): bool
    {
        return DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $userId)->where('r.code', $role)->exists();
    }

    /** @throws AccessRefused */
    public function assign(User $user, string $role, ?User $actor = null): void
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id') ?? throw new AccessRefused('هذا الدور غير موجود.');
        if ($role !== self::ADMIN && ! FeatureFlags::on('multi_admin')) {
            throw new AccessRefused('أدوار فريق الإدارة غير مفعّلة؛ الدور الوحيد المتاح هو «مدير المنصة».');
        }
        try {
            $added = DB::transaction(fn () => DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $roleId]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                throw new AccessRefused('الأدوار لموظفي الإدارة فقط، لا للعملاء.');
            }
            throw $e;
        }
        if ($added > 0) {
            AdminAudit::account('update', $actor, $user, ['role_added' => $role]);
        }
    }

    /** @throws AccessRefused */
    public function revoke(User $user, string $role, ?User $actor = null): void
    {
        $removed = DB::transaction(function () use ($user, $role) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');
            if ($roleId === null) {
                return 0;
            }
            if ($role === self::ADMIN) {
                // Every admin row is locked first, so two admins removing each other at once cannot both pass the check.
                $admins = DB::table('user_roles as ur')->join('users as u', 'u.id', '=', 'ur.user_id')
                    ->where('ur.role_id', $roleId)->where('u.is_active', true)->whereNull('u.deleted_at')
                    ->lockForUpdate()->pluck('ur.user_id');
                if ($admins->count() === 1 && (int) $admins->first() === $user->id) {
                    throw new AccessRefused('لا يمكن سحب دور «مدير المنصة» من آخر مدير فعّال.');
                }
            }

            return DB::table('user_roles')->where('user_id', $user->id)->where('role_id', $roleId)->delete();
        });
        if ($removed > 0) {
            AdminAudit::account('update', $actor, $user, ['role_removed' => $role]);
        }
    }
}
