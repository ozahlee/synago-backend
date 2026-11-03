<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Staff accounts (people who log into the dashboard) — distinct from church members. */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return UserResource::collection(
            User::with('roles.permissions', 'permissions')->orderBy('name')->paginate($this->perPage($request))
        );
    }

    public function store(UserRequest $request): UserResource
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->payload(['roles']));
            $user->syncRoles($request->validated('roles', []));

            return $user;
        });

        return new UserResource($user);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        $this->guardSelfLockout($request, $user);

        DB::transaction(function () use ($request, $user) {
            $user->update($request->payload(['roles']));
            if ($request->has('roles')) {
                $user->syncRoles($request->validated('roles'));
            }
            if ($user->wasChanged('is_active') && ! $user->is_active) {
                $user->tokens()->delete();
            }
        });

        return new UserResource($user->fresh());
    }

    public function destroy(Request $request, User $user): Response
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account.');
        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }

    private function guardSelfLockout(UserRequest $request, User $user): void
    {
        if (! $user->is($request->user())) {
            return;
        }
        abort_if($request->has('isActive') && ! $request->boolean('isActive'), 422, 'You cannot deactivate your own account.');
        abort_if($request->has('roles') && ! in_array('admin', $request->validated('roles'), true) && $user->hasRole('admin'),
            422, 'You cannot remove your own admin role.');
    }
}
