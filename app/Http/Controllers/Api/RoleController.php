<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Create roles and choose their permissions. "admin" is locked to keep someone able to manage access. */
class RoleController extends Controller
{
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::orderBy('name')->pluck('name')]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_ ]*$/', Rule::unique('roles', 'name')],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], ['name.regex' => 'Use lowercase letters, numbers, spaces or underscores.']);
        $role = Role::create(['name' => trim($v['name']), 'guard_name' => 'web', 'church_id' => \App\Support\Tenant::id()]);
        $role->syncPermissions($v['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['data' => ['name' => $role->name]], 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $r = Role::findByName($role, 'web');
        abort_if($r->name === 'admin', 422, 'The admin role always has every permission.');
        $v = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);
        $r->syncPermissions($v['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['data' => ['name' => $r->name, 'permissions' => $r->permissions->pluck('name')]]);
    }

    public function destroy(string $role): Response
    {
        $r = Role::findByName($role, 'web');
        abort_if(array_key_exists($r->name, RolesAndPermissionsSeeder::ROLES), 422, 'Built-in roles can be edited but not deleted.');
        abort_if($r->users()->exists(), 422, 'Move the people in this role to another role first.');
        $r->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->noContent();
    }
}
