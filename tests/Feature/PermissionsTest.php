<?php

namespace Tests\Feature;

class PermissionsTest extends ApiTestCase
{
    public function test_secretary_cannot_touch_finance(): void
    {
        $this->actingAsRole('secretary');

        $this->getJson('/api/v1/offertory')->assertForbidden();
        $this->postJson('/api/v1/accounts', [])->assertForbidden();
        $this->getJson('/api/v1/members')->assertOk();
    }

    public function test_treasurer_can_view_but_not_edit_members(): void
    {
        $this->actingAsRole('treasurer');

        $this->getJson('/api/v1/members')->assertOk();
        $this->postJson('/api/v1/members', [])->assertForbidden();
        $this->getJson('/api/v1/offertory')->assertOk();
    }

    public function test_only_admin_manages_users(): void
    {
        $this->actingAsRole('pastor');
        $this->getJson('/api/v1/users')->assertForbidden();

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/users')->assertOk();
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = $this->actingAsRole('admin');

        $this->patchJson("/api/v1/users/{$admin->id}", ['isActive' => false])->assertStatus(422);
        $this->patchJson("/api/v1/users/{$admin->id}", ['roles' => ['pastor']])->assertStatus(422);
        $this->deleteJson("/api/v1/users/{$admin->id}")->assertStatus(422);
    }
}
