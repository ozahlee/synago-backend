<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Church;
use App\Models\User;
use App\Services\ChurchProvisioner;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->input('email'))->first();

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        if ($user->church_id && Church::find($user->church_id)?->isSuspended()) {
            throw ValidationException::withMessages(['email' => 'Your church account is suspended. Please contact Synago support.']);
        }

        return $this->signedIn($user, $request->input('deviceName', 'api'));
    }

    /** Self sign-up: a new church, its starter set-up and its first admin, on a free trial. */
    public function register(Request $request, ChurchProvisioner $provisioner): JsonResponse
    {
        $v = $request->validate([
            'churchName' => ['required', 'string', 'min:3', 'max:150'],
            'denomination' => ['required', Rule::in(['anglican', 'pentecostal', 'other'])],
            'diocese' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'acceptTerms' => ['accepted'],
        ], ['email.unique' => 'This email already has a Synago account. Sign in instead, or use another email.']);

        [$church, $user] = $provisioner->create(
            ['name' => trim($v['churchName']), 'denomination' => $v['denomination'], 'diocese' => $v['diocese'] ?? null,
                'address' => $v['address'] ?? null, 'phone' => $v['phone'], 'email' => $v['email']],
            ['name' => trim($v['name']), 'email' => strtolower($v['email']), 'phone' => $v['phone'], 'password' => $v['password']],
        );
        $user->sendEmailVerificationNotification();

        return $this->signedIn($user, 'signup', 201);
    }

    /** The link in the verification email: confirm the address, then go back to the app. */
    public function verify(string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This verification link is not valid.');
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->away(rtrim(config('app.frontend_url'), '/').'/?verified=1');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['data' => ['sent' => ! $user->hasVerifiedEmail()]]);
    }

    public function me(Request $request): UserResource
    {
        Tenant::set($request->user()->church_id);

        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function signedIn(User $user, string $device, int $status = 200): JsonResponse
    {
        Tenant::set($user->church_id);
        $token = $user->createToken($device)->plainTextToken;

        return response()->json(['token' => $token, 'user' => new UserResource($user)], $status);
    }
}
