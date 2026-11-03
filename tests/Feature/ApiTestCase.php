<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\User;
use App\Services\ChurchProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Church $church;

    protected function setUp(): void
    {
        parent::setUp();
        // every test runs inside its own church, like a real request would
        $this->church = $this->makeChurch('Test Church');
        Tenant::set($this->church->id);
    }

    protected function tearDown(): void
    {
        Tenant::set(null);
        parent::tearDown();
    }

    protected function makeChurch(string $name): Church
    {
        $church = Church::create(['name' => $name, 'slug' => Church::uniqueSlug($name), 'status' => 'active', 'onboarded_at' => now()]);
        app(ChurchProvisioner::class)->setUp($church);

        return $church;
    }

    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
