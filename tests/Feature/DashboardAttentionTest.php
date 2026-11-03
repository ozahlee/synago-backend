<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Visitor;
use App\Models\WelfareCase;
use Carbon\Carbon;

class DashboardAttentionTest extends ApiTestCase
{
    private function setUpWeek(): string
    {
        $this->travelTo(Carbon::parse('2026-11-04 10:00')); // a Wednesday
        $sunday = '2026-11-01';
        Visitor::create(['name' => 'New friend', 'first_visit' => '2026-10-25', 'status' => 'new']);
        WelfareCase::create(['beneficiary' => 'Okello family', 'category' => 'medical', 'amount_requested' => 100]);
        $m = Member::factory()->create();
        Pledge::create(['member_id' => $m->id, 'purpose' => 'Roof', 'amount' => 500, 'pledge_date' => '2026-01-01', 'due_date' => '2026-10-01']);
        // First Service fully recorded on Sunday; the rest not
        Attendance::create(['date' => $sunday, 'service' => 'First Service', 'men' => 20, 'women' => 30, 'children' => 10, 'visitors' => 2]);
        Offertory::create(['date' => $sunday, 'service' => 'First Service', 'category' => 'tithe', 'amount' => 300, 'payment_method' => 'Cash']);
        Offertory::create(['date' => '2026-10-25', 'service' => 'First Service', 'category' => 'tithe', 'amount' => 200, 'payment_method' => 'Cash']);

        return $sunday;
    }

    public function test_attention_items_follow_the_users_permissions(): void
    {
        $this->setUpWeek();

        $this->actingAsRole('secretary'); // members, no money, no welfare
        $keys = collect($this->getJson('/api/v1/dashboard/attention')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(1, $keys['visitors']['count']);
        $this->assertSame(3, $keys['attendance']['count']);           // three services without attendance
        $this->assertStringContainsString('1 Nov', $keys['attendance']['detail']);
        $this->assertSame('/attendance?date=2026-11-01', $keys['attendance']['href']);
        $this->assertArrayHasKey('preachers', $keys->all());
        $this->assertArrayNotHasKey('pledges', $keys->all());
        $this->assertArrayNotHasKey('offertory', $keys->all());
        $this->assertArrayNotHasKey('welfare', $keys->all());

        $this->actingAsRole('treasurer'); // money + welfare
        $keys = collect($this->getJson('/api/v1/dashboard/attention')->json('data'))->keyBy('key');
        $this->assertSame(1, $keys['pledges']['count']);
        $this->assertSame(3, $keys['offertory']['count']);
        $this->assertSame(1, $keys['welfare']['count']);
        $this->assertArrayNotHasKey('visitors', $keys->all());   // can view members, but not act on visitors
        $this->assertArrayNotHasKey('attendance', $keys->all());

        $this->actingAsRole('pastor'); // people + reads the books, doesn't enter offertory
        $keys = collect($this->getJson('/api/v1/dashboard/attention')->json('data'))->keyBy('key');
        $this->assertArrayHasKey('visitors', $keys->all());
        $this->assertArrayHasKey('pledges', $keys->all());
        $this->assertArrayNotHasKey('offertory', $keys->all());

        $this->actingAsRole('leader'); // position-holder only: nothing church-wide
        $this->getJson('/api/v1/dashboard/attention')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dashboard_and_sunday_hide_figures_the_role_cannot_open(): void
    {
        $sunday = $this->setUpWeek();

        $this->actingAsRole('secretary');
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.monthlyOffertory', null)->assertJsonPath('data.offertoryTrends', [])
            ->assertJsonPath('data.totalMembers', 1);
        $this->getJson('/api/v1/dashboard/sunday')->assertOk()
            ->assertJsonPath('data.date', $sunday)->assertJsonPath('data.showGiving', false)
            ->assertJsonPath('data.totals.attendance', 62)->assertJsonPath('data.totals.giving', null);

        $this->actingAsRole('admin');
        $this->getJson('/api/v1/dashboard/sunday')
            ->assertJsonPath('data.totals.giving', 300)->assertJsonPath('data.totals.givingPrev', 200)
            ->assertJsonPath('data.services.0.service', 'First Service')->assertJsonPath('data.services.0.visitors', 2);
    }
}
