<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\Sermon;
use App\Models\Setting;
use App\Services\Calendar\ReminderService;
use App\Support\Scripture;
use Illuminate\Support\Carbon;

class SermonTest extends ApiTestCase
{
    public function test_scripture_references_are_normalised(): void
    {
        $this->assertSame('John 15:1–8', Scripture::parse('Jn 15 1-8')['ref']);
        $this->assertSame('1 Corinthians 13:4–7', Scripture::parse('1cor 13:4-7')['ref']);
        $this->assertSame('Psalm 23', Scripture::parse('ps 23')['ref']);
        $this->assertSame('Jude 1:3', Scripture::parse('jude 3')['ref']);
        $this->assertSame('NT', Scripture::parse('Rev 21:1-22:5')['testament']);
        $this->assertNull(Scripture::parse('banana 3'));
        $this->assertNull(Scripture::parse('jo 3')); // ambiguous (Job? John? Jonah? Joel?)
    }

    public function test_record_sermon_with_passages_and_service_numbers(): void
    {
        $this->actingAsRole('secretary');
        $m = Member::factory()->create(['name' => 'Rev. Canon Okello']);
        Attendance::create(['date' => '2026-11-01', 'service' => 'Second Service', 'men' => 20, 'women' => 30, 'children' => 10, 'visitors' => 2]);
        Offertory::factory()->create(['date' => '2026-11-01', 'service' => 'Second Service', 'amount' => 500]);

        $id = $this->postJson('/api/v1/sermons', [
            'date' => '2026-11-01', 'service' => 'Second Service', 'status' => 'preached', 'preacherMemberId' => $m->id,
            'title' => 'Abide in me', 'theme' => 'Fruitfulness', 'scriptures' => ['Jn 15 1-8', 'gal 5:22-23', ''],
        ])->assertCreated()
            ->assertJsonPath('data.scriptures', ['John 15:1–8', 'Galatians 5:22–23'])
            ->assertJsonPath('data.preacher', 'Rev. Canon Okello')
            ->json('data.id');

        $this->getJson("/api/v1/sermons/{$id}")->assertOk()->assertJsonPath('data.attendance', 62)->assertJsonPath('data.offertory', 500);

        $this->postJson('/api/v1/sermons', ['date' => '2026-11-08', 'scriptures' => ['Hezekiah 3:4']])
            ->assertStatus(422)->assertJsonValidationErrors('scriptures.0');

        // search by book (John = 42) and by text
        $this->getJson('/api/v1/sermons?book=42')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sermons?book=0')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sermons?search=Galatians')->assertOk()->assertJsonCount(1, 'data');
        $coverage = collect($this->getJson('/api/v1/sermons/stats?year=2026')->assertOk()->json('data.coverage'))->keyBy('book');
        $this->assertSame(1, $coverage['John']['count']);
        $this->assertSame(1, $coverage['Galatians']['count']);
    }

    public function test_visiting_preacher_and_rota_gaps(): void
    {
        $this->actingAsRole('pastor');
        Setting::put(['services' => ['First Service', 'Second Service']]);
        $sunday = ReminderService::now()->next(Carbon::SUNDAY)->toDateString();
        $this->postJson('/api/v1/sermons', ['date' => $sunday, 'service' => 'First Service', 'preacherName' => 'Bishop Banja', 'preacherChurch' => 'Namirembe Cathedral'])
            ->assertCreated()->assertJsonPath('data.visiting', true)->assertJsonPath('data.status', 'planned');

        $gaps = collect($this->getJson('/api/v1/sermons/stats')->assertOk()->json('data.gaps'));
        $this->assertFalse($gaps->contains(fn ($g) => $g['date'] === $sunday && $g['service'] === 'First Service'));
        $this->assertTrue($gaps->contains(fn ($g) => $g['date'] === $sunday && $g['service'] === 'Second Service'));

        $items = collect($this->getJson("/api/v1/calendar?from={$sunday}&to={$sunday}&kinds=sermon")->assertOk()->json('data.items'));
        $this->assertSame('Preaching: Bishop Banja', $items->first()['title']);
    }

    public function test_preacher_gets_rota_reminder(): void
    {
        $this->actingAsRole('admin');
        Setting::put(['smsMonthlyBudget' => 0]);
        ReminderRule::create(['key' => 'preacher_rota', 'enabled' => true]);
        $m = Member::factory()->create(['name' => 'Grace Namutebi', 'phone' => '0772333444']);
        Sermon::create(['date' => '2026-11-08', 'service' => 'Second Service', 'preacher_member_id' => $m->id, 'theme' => 'Hope', 'scriptures' => ['Romans 8:28']]);

        app(ReminderService::class)->run(Carbon::parse('2026-11-05 09:01')); // 3 days before
        $r = Reminder::where('source', 'rule:preacher_rota')->first();
        $this->assertSame('sent', $r->status);
        $this->assertStringContainsString('Second Service on Sunday 8 Nov (theme: Hope), reading Romans 8:28', $r->message);
        $this->assertDatabaseHas('sms_messages', ['phone' => '+256772333444', 'status' => 'sent']);
    }

    public function test_year_ranges_include_the_last_day_of_the_year(): void
    {
        $this->actingAsRole('treasurer');
        $m = Member::factory()->create();
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 75, 'date' => '2026-12-31']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'harvest', 'amount' => 40, 'date' => '2026-12-31']);

        $this->getJson('/api/v1/reports/tithes?year=2026')->assertOk()->assertJsonPath('data.total', 75);
        $this->getJson("/api/v1/reports/statement?memberId={$m->id}&year=2026")->assertOk()->assertJsonPath('data.offertoryTotal', 115);
        $this->getJson('/api/v1/reports/harvest?year=2026')->assertOk()->assertJsonPath('data.total', 40);
    }

    public function test_permissions(): void
    {
        $this->actingAsRole('treasurer'); // members.view only
        $this->getJson('/api/v1/sermons')->assertOk();
        $this->postJson('/api/v1/sermons', ['date' => '2026-11-08'])->assertForbidden();
    }
}
