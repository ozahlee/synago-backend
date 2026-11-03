<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\User;

class DashboardAndAlertsTest extends ApiTestCase
{
    public function test_dashboard_stats_shape(): void
    {
        $this->actingAsRole('pastor');
        Member::factory()->count(3)->create(['gender' => 'male', 'status' => 'active']);
        Member::factory()->inactive()->create(['gender' => 'female']);
        Offertory::factory()->create(['date' => now(), 'category' => 'tithe', 'amount' => 100]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.totalMembers', 4)
            ->assertJsonPath('data.activeMembers', 3)
            ->assertJsonPath('data.membershipByGender', ['male' => 3, 'female' => 1])
            ->assertJsonPath('data.monthlyOffertory', 100)
            ->assertJsonCount(6, 'data.offertoryTrends')
            ->assertJsonPath('data.offertoryTrends.5.tithe', 100)
            ->assertJsonStructure(['data' => ['recentActivities' => [['id', 'name', 'action', 'time']]]]);
    }

    public function test_users_only_see_broadcast_and_own_alerts(): void
    {
        $me = $this->actingAsRole('treasurer');
        $other = User::factory()->create();
        Alert::factory()->create(['title' => 'Everyone']);
        Alert::factory()->create(['title' => 'Mine', 'user_id' => $me->id]);
        $theirs = Alert::factory()->create(['title' => 'Theirs', 'user_id' => $other->id]);

        $this->getJson('/api/v1/notifications')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 2);
        $this->patchJson("/api/v1/notifications/{$theirs->id}", ['read' => true])->assertNotFound();

        $this->postJson('/api/v1/notifications/read-all')->assertNoContent();
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.count', 0);
        $this->assertFalse($theirs->fresh()->read);
    }
}
