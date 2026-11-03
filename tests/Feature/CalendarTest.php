<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Event;
use App\Models\Member;
use App\Models\Position;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\SundaySchoolClass;
use App\Models\User;
use App\Services\Calendar\ChurchYear;
use App\Services\Calendar\ReminderService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class CalendarTest extends ApiTestCase
{
    public function test_church_year_is_computed_from_easter(): void
    {
        $this->assertSame('2026-04-05', ChurchYear::easter(2026)->toDateString());
        $this->assertSame('2027-03-28', ChurchYear::easter(2027)->toDateString());
        $this->assertSame('2023-12-03', ChurchYear::adventSunday(2023)->toDateString()); // Christmas Eve on a Sunday
        $lent = collect(ChurchYear::seasons(2026))->firstWhere('name', 'Lent');
        $this->assertSame('2026-02-18', $lent['start']); // Ash Wednesday
        $this->assertSame('2026-06-03', collect(ChurchYear::feasts(2026))->firstWhere('name', 'Uganda Martyrs Day')['start']);

        $this->actingAsRole('secretary');
        $this->postJson('/api/v1/observances', ['name' => 'Patronal festival', 'rule' => 'fixed', 'month' => 7, 'day' => 25, 'colour' => 'red'])->assertCreated();
        $this->postJson('/api/v1/observances', ['name' => 'Harvest Sunday', 'rule' => 'easter', 'easterOffset' => 120, 'colour' => 'gold'])->assertStatus(422);
        $this->postJson('/api/v1/observances', ['name' => 'Bad', 'rule' => 'fixed', 'month' => 2, 'day' => 31, 'colour' => 'red'])->assertStatus(422);
        $this->getJson('/api/v1/calendar/church-year?year=2026')->assertOk()->assertJsonPath('data.easter', '2026-04-05')
            ->assertJsonFragment(['name' => 'Patronal festival', 'start' => '2026-07-25']);
    }

    public function test_repeating_events_and_first_sunday_rule(): void
    {
        $this->actingAsRole('secretary');
        // Holy Communion every first Sunday, starting 1 Nov 2026 (a Sunday)
        $this->postJson('/api/v1/events', ['title' => 'Holy Communion', 'type' => 'communion', 'startsAt' => '2026-11-01T09:30', 'endsAt' => '2026-11-01T11:00', 'repeat' => 'monthly_nth'])
            ->assertCreated();
        $items = collect($this->getJson('/api/v1/calendar?from=2026-11-01&to=2027-01-31&kinds=event')->assertOk()->json('data.items'));
        $this->assertSame(['2026-11-01T09:30', '2026-12-06T09:30', '2027-01-03T09:30'], $items->pluck('start')->all());
    }

    public function test_venue_double_booking_needs_confirmation(): void
    {
        $this->actingAsRole('pastor');
        $this->postJson('/api/v1/events', ['title' => 'Youth conference', 'type' => 'meeting', 'startsAt' => '2026-11-14T10:00', 'endsAt' => '2026-11-14T16:00', 'venue' => 'Church hall'])->assertCreated();
        $clash = ['title' => "Mothers' Union meeting", 'type' => 'meeting', 'startsAt' => '2026-11-14T14:00', 'endsAt' => '2026-11-14T15:00', 'venue' => 'Church hall'];

        $this->postJson('/api/v1/events', $clash)->assertStatus(409)->assertJsonPath('clashes.0.title', 'Youth conference');
        $this->postJson('/api/v1/events', [...$clash, 'ignoreClash' => true])->assertCreated();
        $this->postJson('/api/v1/events', [...$clash, 'startsAt' => '2026-11-14T16:00', 'endsAt' => '2026-11-14T17:00'])->assertCreated(); // back-to-back is fine
    }

    public function test_calendar_combines_modules_and_scopes_leaders(): void
    {
        $this->actingAsRole('admin');
        Setting::put(['services' => ['First Service'], 'serviceTimes' => ['08:00']]);
        Member::factory()->create(['name' => 'Birthday Person', 'date_of_birth' => '1990-11-03', 'status' => 'active']);
        $items = collect($this->getJson('/api/v1/calendar?from=2026-11-01&to=2026-11-07')->assertOk()->json('data.items'));
        $this->assertTrue($items->contains(fn ($i) => $i['kind'] === 'service' && $i['start'] === '2026-11-01T08:00'));
        $this->assertTrue($items->contains(fn ($i) => $i['kind'] === 'birthday' && $i['title'] === 'Birthday Person'));

        // A Sunday school teacher's login sees services but no member birthdays
        $class = SundaySchoolClass::create(['name' => 'Juniors', 'min_age' => 6, 'max_age' => 12]);
        $m = Member::factory()->create(['date_of_birth' => '1985-01-01']);
        Position::create(['member_id' => $m->id, 'title' => 'Teacher', 'scope_type' => 'sunday_school_class', 'scope_id' => $class->id]);
        $leader = User::factory()->create(['member_id' => $m->id]);
        $leader->assignRole('leader');
        Sanctum::actingAs($leader);
        $items = collect($this->getJson('/api/v1/calendar?from=2026-11-01&to=2026-11-07')->assertOk()->json('data.items'));
        $this->assertTrue($items->contains('kind', 'service'));
        $this->assertFalse($items->contains('kind', 'birthday'));
    }

    public function test_birthday_rule_sends_once_and_tells_staff(): void
    {
        $this->actingAsRole('admin');
        Setting::put(['smsMonthlyBudget' => 0]);
        Member::factory()->create(['name' => 'Ruth Nalwoga', 'phone' => '0772111222', 'date_of_birth' => '1990-11-03', 'status' => 'active']);
        ReminderRule::create(['key' => 'birthdays', 'enabled' => true]);
        $svc = app(ReminderService::class);

        $r = $svc->run(Carbon::parse('2026-11-03 07:05'));
        $this->assertSame(2, $r['created']);
        $this->assertSame(2, $r['sent']);
        $this->assertDatabaseHas('sms_messages', ['phone' => '+256772111222', 'status' => 'sent']);
        $this->assertTrue(Alert::where('message', 'like', 'Birthdays today: Ruth Nalwoga%')->exists());

        $again = $svc->run(Carbon::parse('2026-11-03 07:10'));
        $this->assertSame(0, $again['created']);
        $this->assertSame(1, SmsMessage::count());
    }

    public function test_event_reminders_respect_quiet_hours_and_budget(): void
    {
        $this->actingAsRole('admin');
        Setting::put(['quietHoursStart' => '20:00', 'quietHoursEnd' => '07:00', 'smsMonthlyBudget' => 0]);
        Member::factory()->count(2)->sequence(['phone' => '0772000001'], ['phone' => '0772000002'])->create(['status' => 'active']);
        Event::create(['title' => 'Prayer night', 'type' => 'meeting', 'starts_at' => '2026-11-05 22:00', 'remind_minutes' => [60], 'remind_audience' => ['type' => 'all']]);
        $svc = app(ReminderService::class);

        $svc->run(Carbon::parse('2026-11-05 21:00')); // reminder due at 21:00, inside quiet hours
        $rem = Reminder::where('source', 'event')->first();
        $this->assertSame('pending', $rem->status);
        $this->assertSame('2026-11-06 07:00', $rem->remind_at->format('Y-m-d H:i'));

        Setting::put(['smsMonthlyBudget' => 1]);
        $svc->run(Carbon::parse('2026-11-06 07:01'));
        $this->assertSame('skipped', $rem->fresh()->status);
        $this->assertStringContainsString('budget', $rem->fresh()->note);
    }

    public function test_personal_reminders_and_permissions(): void
    {
        $user = $this->actingAsRole('treasurer');
        $this->postJson('/api/v1/reminders', ['remindAt' => '2026-11-05T10:00', 'message' => 'Bank the offertory'])->assertCreated()
            ->assertJsonPath('data.audienceLabel', 'Just me')->assertJsonPath('data.channel', 'app');
        $this->postJson('/api/v1/reminders', ['remindAt' => '2026-11-05T10:00', 'message' => 'x', 'audience' => ['type' => 'all']])->assertForbidden();

        app(ReminderService::class)->run(Carbon::parse('2026-11-05 10:01'));
        $this->assertDatabaseHas('alerts', ['user_id' => $user->id, 'message' => 'Bank the offertory']);
        $this->getJson('/api/v1/reminder-rules')->assertForbidden();
    }

    public function test_phone_feed_is_ics_with_token(): void
    {
        $this->actingAsRole('secretary');
        Event::create(['title' => 'Crusade', 'type' => 'crusade', 'starts_at' => now()->addDays(5)->setTime(14, 0)]);
        $url = $this->getJson('/api/v1/calendar/feed-link')->assertOk()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);

        $this->app['auth']->forgetGuards();
        $res = $this->get($path)->assertOk();
        $this->assertStringContainsString('text/calendar', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('SUMMARY:Crusade', $res->getContent());
        $this->assertStringContainsString('Easter Day', $res->getContent());
        $this->get('/api/v1/calendar/feed/not-a-token.ics')->assertNotFound();
    }
}
