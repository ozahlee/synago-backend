<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ChurchRegistersTest extends ApiTestCase
{
    public function test_members_get_sequential_pledge_numbers_and_are_searchable_by_them(): void
    {
        $this->actingAsRole('secretary');
        Setting::put(['pledgePrefix' => 'SJW']);

        $a = Member::factory()->create();
        $b = Member::factory()->create();
        $this->assertSame('SJW-0001', $a->pledge_number);
        $this->assertSame('SJW-0002', $b->pledge_number);

        $b->delete(); // soft-deleted numbers are never reused
        $this->assertSame('SJW-0003', Member::factory()->create()->pledge_number);

        $this->getJson('/api/v1/members?search=SJW-0001')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.pledgeNumber', 'SJW-0001');

        $this->postJson('/api/v1/members', ['name' => 'Manual', 'gender' => 'male', 'dateJoined' => '2026-01-01', 'pledgeNumber' => 'SJW-0001'])
            ->assertStatus(422)->assertJsonValidationErrors('pledgeNumber');
    }

    public function test_sunday_breakdown_by_service_with_day_total(): void
    {
        $this->actingAsRole('treasurer');
        Setting::put(['services' => ['First Service', 'Second Service']]);
        Offertory::factory()->create(['date' => '2026-11-01', 'service' => 'Second Service', 'category' => 'tithe', 'amount' => 300]);
        Offertory::factory()->create(['date' => '2026-11-01', 'service' => 'First Service', 'category' => 'tithe', 'amount' => 100]);
        Offertory::factory()->create(['date' => '2026-11-01', 'service' => 'First Service', 'category' => 'harvest', 'amount' => 50]);
        Offertory::factory()->create(['date' => '2026-11-01', 'service' => null, 'category' => 'pledge', 'amount' => 25]);
        Offertory::factory()->create(['date' => '2026-10-25', 'service' => 'First Service', 'category' => 'tithe', 'amount' => 999]);

        $this->getJson('/api/v1/offertory/day?date=2026-11-01')
            ->assertOk()
            ->assertJsonPath('data.total', 475)
            ->assertJsonPath('data.count', 4)
            ->assertJsonPath('data.byCategory.tithe', 400)
            ->assertJsonPath('data.services.0.service', 'First Service')
            ->assertJsonPath('data.services.0.total', 150)
            ->assertJsonPath('data.services.0.byCategory.harvest', 50)
            ->assertJsonPath('data.services.1.total', 300)
            ->assertJsonPath('data.services.2.service', null)
            ->assertJsonPath('data.services.2.total', 25);

        $this->getJson('/api/v1/offertory/days?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '2026-11-01')
            ->assertJsonPath('data.0.total', 475)
            ->assertJsonPath('data.1.total', 999);

        $this->getJson('/api/v1/offertory?service=Second%20Service')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tithe_report_groups_by_member_and_month(): void
    {
        $this->actingAsRole('treasurer');
        $m = Member::factory()->create(['name' => 'Ruth Nalwoga']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 100, 'date' => '2026-01-04']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 50, 'date' => '2026-01-25']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 70, 'date' => '2026-03-01']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'pledge', 'amount' => 999, 'date' => '2026-03-01']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 999, 'date' => '2025-12-31']);
        Offertory::factory()->create(['member_id' => null, 'contributor_name' => 'Visitor Joe', 'category' => 'tithe', 'amount' => 10, 'date' => '2026-02-01']);

        $res = $this->getJson('/api/v1/reports/tithes?year=2026')->assertOk()
            ->assertJsonPath('data.total', 230)
            ->assertJsonPath('data.tithers', 2)
            ->assertJsonPath('data.monthTotals.0', 150)
            ->assertJsonPath('data.monthTotals.1', 10);

        $ruth = collect($res->json('data.rows'))->firstWhere('name', 'Ruth Nalwoga');
        $this->assertSame([150, 0, 70, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_map('intval', $ruth['months']));
        $this->assertSame(2, $ruth['monthsGiven']);
        $this->assertSame($m->pledge_number, $ruth['pledgeNumber']);

        $this->actingAsRole('secretary');
        $this->getJson('/api/v1/reports/tithes')->assertForbidden();
    }

    public function test_announcements_upcoming_vs_past_and_permissions(): void
    {
        $this->actingAsRole('secretary');
        $this->postJson('/api/v1/announcements', [
            'type' => 'burial', 'title' => 'Burial arrangements', 'subject' => 'The late Mzee Yosefu Kato',
            'eventAt' => now()->addDays(3)->setTime(10, 0)->format('Y-m-d\TH:i'), 'venue' => 'Kireka',
            'contactName' => 'Paul Kato', 'contactPhone' => '0772000000',
        ])->assertCreated()->assertJsonPath('data.type', 'burial');
        Announcement::create(['type' => 'wedding', 'title' => 'Old wedding', 'event_at' => now()->subWeek()]);

        $this->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'The late Mzee Yosefu Kato');
        $this->getJson('/api/v1/announcements?when=past')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsRole('treasurer');
        $this->getJson('/api/v1/announcements')->assertOk();
        $this->postJson('/api/v1/announcements', ['type' => 'general', 'title' => 'x'])->assertForbidden();
    }

    public function test_profile_update_and_password_change(): void
    {
        $user = $this->actingAsRole('treasurer');

        $this->patchJson('/api/v1/auth/me', ['name' => 'New Name', 'phone' => '0700'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->putJson('/api/v1/auth/password', ['currentPassword' => 'wrong', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])
            ->assertStatus(422)->assertJsonValidationErrors('currentPassword');

        $this->putJson('/api/v1/auth/password', ['currentPassword' => 'password', 'password' => 'newpassword1', 'password_confirmation' => 'newpassword1'])
            ->assertNoContent();
        $this->assertTrue(Hash::check('newpassword1', $user->fresh()->password));
    }

    public function test_roles_catalogue_is_admin_only(): void
    {
        $this->actingAsRole('treasurer');
        $this->getJson('/api/v1/roles')->assertForbidden();

        $this->actingAsRole('admin');
        $roles = collect($this->getJson('/api/v1/roles')->assertOk()->json('data'))->keyBy('name');
        $this->assertContains('settings.manage', $roles['admin']['permissions']);
        $this->assertNotContains('finance.manage', $roles['secretary']['permissions']);
    }
}
