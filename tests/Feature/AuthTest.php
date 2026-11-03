<?php

namespace Tests\Feature;

use App\Models\User;

class AuthTest extends ApiTestCase
{
    public function test_login_returns_token_and_user_with_permissions(): void
    {
        $user = User::factory()->create(['email' => 'pastor@church.test']);
        $user->assignRole('pastor');

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'pastor@church.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.roles', ['pastor']);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk()
            ->assertJsonPath('data.email', 'pastor@church.test');
    }

    public function test_login_rejects_bad_password_and_inactive_users(): void
    {
        User::factory()->create(['email' => 'a@church.test']);
        User::factory()->create(['email' => 'b@church.test', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@church.test', 'password' => 'nope'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'b@church.test', 'password' => 'password'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'This account has been deactivated.');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => "Bearer $token"])->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_requests_get_json_401(): void
    {
        $this->getJson('/api/v1/members')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_deactivated_user_with_existing_token_is_blocked(): void
    {
        $this->actingAsRole('admin')->update(['is_active' => false]);

        $this->getJson('/api/v1/dashboard')->assertForbidden();
    }
}
