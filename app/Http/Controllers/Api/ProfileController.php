<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/** The signed-in staff member's own account, plus the role catalogue for the access screen. */
class ProfileController extends Controller
{
    public function update(Request $request): UserResource
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $user->update($data);

        return new UserResource($user->fresh());
    }

    public function password(Request $request): Response
    {
        $request->validate([
            'currentPassword' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();
        $user->update(['password' => $request->input('password')]);
        // Sign out every other device; keep this session.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))->delete();

        return response()->noContent();
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::with('permissions')->where('church_id', \App\Support\Tenant::id())->orderBy('name')->get()->map(fn (Role $r) => [
            'name' => $r->name,
            'permissions' => $r->permissions->pluck('name')->sort()->values(),
            'users' => $r->users()->count(),
        ])]);
    }
}
