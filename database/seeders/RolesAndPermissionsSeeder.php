<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Idempotent: safe to re-run after adding permissions/roles. */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'members.view',
        'members.manage',
        'ministries.manage',
        'finance.view',
        'finance.manage',
        'alerts.manage',
        'users.manage',
        'settings.manage',
        'welfare.manage',
        'sms.send',
        'sacraments.override',
        'groups.lead',
    ];

    public const ROLES = [
        'admin' => self::PERMISSIONS,
        'pastor' => ['members.view', 'members.manage', 'ministries.manage', 'finance.view', 'alerts.manage', 'welfare.manage', 'sms.send', 'sacraments.override'],
        'secretary' => ['members.view', 'members.manage', 'ministries.manage', 'alerts.manage', 'sms.send'],
        'treasurer' => ['members.view', 'finance.view', 'finance.manage', 'welfare.manage'],
        // Position-holders (Sunday school teachers, ministry chairs, cell leaders): only "My groups".
        'leader' => ['groups.lead'],
    ];

    /**
     * Permissions are platform-wide; roles belong to each church. With a current church (Tenant) only that
     * church's roles are set up; otherwise every church's.
     */
    public function run(): void
    {
        $added = self::permissions();
        $churches = \App\Support\Tenant::id() ? [\App\Support\Tenant::id()] : \App\Models\Church::pluck('id')->all();
        foreach ($churches as $id) {
            self::rolesFor($id, $added);
        }
    }

    /** Make sure every permission exists; returns the ones just created. */
    public static function permissions(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $added = [];
        foreach (self::PERMISSIONS as $name) {
            if (Permission::findOrCreate($name, 'web')->wasRecentlyCreated) {
                $added[] = $name;
            }
        }

        return $added;
    }

    /**
     * The built-in roles for one church. Re-running keeps a church's own edits in the role editor:
     * roles are only reset when first created (and admin always has everything).
     */
    public static function rolesFor(int $churchId, array $newPermissions = []): void
    {
        $previous = getPermissionsTeamId();
        setPermissionsTeamId($churchId);
        foreach (self::ROLES as $role => $permissions) {
            $r = Role::where('church_id', $churchId)->where('name', $role)->where('guard_name', 'web')->first();
            $created = false;
            if (! $r) {
                $r = Role::create(['name' => $role, 'guard_name' => 'web', 'church_id' => $churchId]);
                $created = true;
            }
            if ($created || $role === 'admin') {
                $r->syncPermissions($permissions);
            } else {
                $r->givePermissionTo(array_values(array_intersect($permissions, $newPermissions)));
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        setPermissionsTeamId($previous);
    }
}
