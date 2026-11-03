<?php

namespace Database\Seeders;

use App\Http\Controllers\Api\AreaController;
use App\Http\Controllers\Api\SundaySchoolController;
use App\Models\Announcement;
use App\Models\CareCase;
use App\Models\Ministry;
use App\Models\Outreach;
use App\Models\PastoralVisit;
use App\Models\Position;
use App\Models\Project;
use App\Models\SundaySchoolClass;
use App\Models\Attendance;
use App\Models\Baptism;
use App\Models\CellGroup;
use App\Models\Marriage;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Visitor;
use App\Models\WelfareCase;
use Illuminate\Database\Seeder;

/**
 * Demo rows for the church registers added after the MVP (baptisms, …).
 * Idempotent per register, so it can run against a database that already has the MVP demo data.
 */
class RegistersDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! Baptism::exists()) {
            $this->baptisms();
        }
        if (! Announcement::exists()) {
            $this->announcements();
        }
        if (! Offertory::whereNotNull('service')->exists()) {
            $this->assignServices();
        }
        if (! Attendance::exists()) {
            $this->attendance();
        }
        if (! Marriage::exists()) {
            $this->marriages();
        }
        if (! Pledge::exists()) {
            $this->pledges();
        }
        if (! Visitor::exists()) {
            $this->visitors();
        }
        if (! WelfareCase::exists()) {
            $this->welfare();
        }
        if (! CellGroup::exists()) {
            $this->cellGroups();
        }
        if (! SundaySchoolClass::exists()) {
            $this->sundaySchool();
        }
        if (! \App\Models\Area::exists()) {
            app(AreaController::class)->fromMembers();
            $parishes = \App\Models\Area::where('level', 'parish')->get();
            $arch = \App\Models\Area::create(['name' => 'Kira Archdeaconry', 'level' => 'archdeaconry']);
            $parishes->each->update(['parent_id' => $arch->id]);
            CellGroup::all()->each(fn ($c) => $c->update(['harvest_target' => rand(8, 20) * 1000]));
        }
        if (! Position::exists()) {
            $this->positions();
        }
        if (! CareCase::exists()) {
            $this->care();
        }
        if (! Project::where('name', 'Community borehole')->exists()) {
            $this->projects();
        }
        $this->baptismRequirements();
        if (! \App\Models\Event::exists()) {
            $this->calendar();
        }
        if (! \App\Models\Sermon::exists()) {
            $this->sermons();
        }
        if (! \App\Models\Household::exists()) {
            $this->familiesAndGiving();
        }
        if (! \App\Models\Journal::exists()) {
            $this->books();
        }
    }

    /** Opening balances at the start of the year and monthly petty-cash withdrawals, so the statements look like a real parish's. */
    private function books(): void
    {
        $ledger = new \App\Services\Accounting\Ledger;
        $acc = \App\Models\Account::pluck('id', 'code');
        $admin = \App\Models\User::role('admin')->first()?->id;
        $start = $ledger->yearStart(\App\Support\ChurchDate::today());
        $building = Project::where('name', 'New church building')->first();

        // restricted opening balance large enough that the building fund isn't overspent
        $bs = $ledger->balanceSheet(\App\Support\ChurchDate::today());
        $buildingNow = $building ? (float) (collect($bs['funds']['rows'])->firstWhere('key', "p:{$building->id}")['amount'] ?? 0) : 0;
        $buildingOpening = $building ? max(250000, ceil((-$buildingNow + 50000) / 10000) * 10000) : 0;

        $assets = ['1000' => 50000, '1010' => 10000, '1020' => 300000, '1030' => 500000, '1500' => 8000000, '1510' => 900000];
        $credits = ['1590' => 180000, '2100' => 400000, '2200' => 60000, '3100' => $buildingOpening];
        $credits['3000'] = array_sum($assets) - array_sum($credits);
        $lines = collect($assets)->map(fn ($v, $c) => ['account_id' => $acc[$c], 'debit' => $v, 'credit' => 0, 'fund' => 'general'])
            ->merge(collect($credits)->filter()->map(fn ($v, $c) => ['account_id' => $acc[$c], 'debit' => 0, 'credit' => $v,
                'fund' => (string) $c === '3100' ? 'project' : 'general', 'project_id' => (string) $c === '3100' ? $building?->id : null]));
        $j = \App\Models\Journal::create(['date' => $start, 'memo' => 'Opening balances brought forward', 'kind' => 'opening', 'recorded_by' => $admin]);
        $lines->each(fn ($l) => $j->lines()->create($l));

        // expenses are often paid in cash: draw petty cash from the bank each month so cash at hand stays positive
        $cash = (float) collect((new \App\Services\Accounting\Ledger)->balanceSheet(\App\Support\ChurchDate::today())['assets']['current'])->firstWhere('code', '1000')['amount'];
        $months = collect(range(0, 5))->map(fn ($i) => now()->subMonths($i)->startOfMonth()->addDays(2)->toDateString())->filter(fn ($d) => $d >= $start)->values();
        $each = max(10000, ceil(max(0, -$cash + 40000) / max(1, $months->count()) / 1000) * 1000);
        foreach ($months as $d) {
            $t = \App\Models\Journal::create(['date' => $d, 'memo' => 'Petty cash drawn from bank', 'kind' => 'transfer', 'recorded_by' => $admin]);
            $t->lines()->create(['account_id' => $acc['1000'], 'debit' => $each, 'credit' => 0, 'fund' => 'general']);
            $t->lines()->create(['account_id' => $acc['1020'], 'debit' => 0, 'credit' => $each, 'fund' => 'general']);
        }
    }

    /** Families by shared surname, organisations, a land project with milestones, and varied project giving. */
    private function familiesAndGiving(): void
    {
        $admin = \App\Models\User::role('admin')->first();
        $giving = app(\App\Services\ContributionService::class);

        $bySurname = Member::active()->get()->groupBy(fn ($m) => \Illuminate\Support\Str::of($m->name)->replaceMatches('/\s+(I{1,3}|IV|V|Jr\.?|Sr\.?|MD|PhD|DVM|DDS)$/i', '')->afterLast(' ')->toString())
            ->filter(fn ($g) => $g->count() >= 2);
        $families = collect();
        foreach ($bySurname as $surname => $people) {
            $head = $people->sortBy(fn ($m) => $m->date_of_birth?->timestamp ?? PHP_INT_MAX)->first();
            $h = \App\Models\Household::create(['name' => "The {$surname} family", 'head_member_id' => $head->id, 'village' => $head->village]);
            Member::whereIn('id', $people->pluck('id'))->update(['household_id' => $h->id]);
            $families->push($h);
        }

        $orgs = collect([
            ['Kireka Hardware Ltd', 'company', 'Mr. Joseph Mugisha', '0772 410 220'],
            ['Compassion Uganda', 'ngo', 'Partnership office', '0414 250 300'],
            ['St. Paul\'s Cathedral, Namirembe', 'church', 'Provost\'s office', '0414 271 000'],
        ])->map(fn ($o) => \App\Models\Organisation::create(['name' => $o[0], 'type' => $o[1], 'contact_name' => $o[2], 'phone' => $o[3]]));

        $land = Project::create(['name' => 'Land for the church school', 'description' => 'Two acres next to the church for a nursery and primary school.',
            'budget' => 60000, 'target' => 60000, 'status' => 'active', 'open_for_contributions' => true, 'start_date' => now()->subMonths(2),
            'deadline' => now()->addMonths(6), 'suggested_family_amount' => 2000]);
        foreach ([['Deposit (30%) to the seller', 18000, -20, 'done'], ['Survey and boundary opening', 2500, 20, 'pending'],
            ['Balance (70%)', 42000, 120, 'pending'], ['Title transfer and registration', 3000, 150, 'pending']] as $i => [$title, $amt, $days, $status]) {
            \App\Models\ProjectMilestone::create(['project_id' => $land->id, 'title' => $title, 'amount' => $amt, 'due_date' => now()->addDays($days),
                'status' => $status, 'done_on' => $status === 'done' ? now()->addDays($days) : null, 'position' => $i]);
        }
        $building = Project::where('name', 'New church building')->first();
        $cells = CellGroup::all();
        foreach ($cells as $c) {
            \Illuminate\Support\Facades\DB::table('project_cell_targets')->insert(['project_id' => $land->id, 'cell_group_id' => $c->id, 'target' => rand(8, 16) * 1000]);
        }

        $sunday = now()->startOfWeek()->subDay();
        foreach ($families->shuffle()->take(8) as $i => $h) {
            $giving->record($land, ['kind' => $i % 2 ? 'gift' : 'thanksgiving', 'contributor_type' => 'household', 'household_id' => $h->id,
                'amount' => rand(5, 25) * 100, 'date' => $sunday->copy()->subWeeks(rand(0, 6)), 'payment_method' => 'Cash', 'service' => 'Second Service'], $admin);
        }
        foreach ($cells as $c) {
            $giving->record($land, ['kind' => 'gift', 'contributor_type' => 'cell_group', 'cell_group_id' => $c->id, 'amount' => rand(10, 40) * 100,
                'date' => now()->subDays(rand(3, 40)), 'payment_method' => 'Mobile Money'], $admin);
        }
        $giving->record($land, ['kind' => 'gift', 'contributor_type' => 'organisation', 'organisation_id' => $orgs[2]->id, 'amount' => 10000,
            'date' => now()->subDays(25), 'payment_method' => 'Bank Transfer', 'notes' => 'Diocesan support for church schools'], $admin);
        if ($building) {
            $giving->record($building, ['kind' => 'in_kind', 'contributor_type' => 'organisation', 'organisation_id' => $orgs[0]->id,
                'in_kind_description' => '50 bags of cement', 'in_kind_value' => 1750, 'date' => now()->subDays(12)], $admin);
            $giving->record($building, ['kind' => 'in_kind', 'contributor_type' => 'named', 'contributor_name' => 'Eng. David Ssekandi',
                'in_kind_description' => 'Structural drawings (free of charge)', 'in_kind_value' => 4000, 'date' => now()->subDays(60)], $admin);
            $mu = \App\Models\Ministry::where('name', "Women's Ministry")->first();
            if ($mu) {
                $giving->record($building, ['kind' => 'gift', 'contributor_type' => 'ministry', 'ministry_id' => $mu->id, 'amount' => 3500,
                    'date' => now()->subDays(18), 'payment_method' => 'Cash'], $admin);
            }
            // a fundraising Sunday linked to the building
            $event = \App\Models\Event::create(['title' => 'Building fundraising Sunday', 'type' => 'fundraising', 'starts_at' => $sunday->copy()->subWeeks(2)->setTime(9, 0),
                'all_day' => true, 'venue' => 'Church grounds', 'project_id' => $building->id]);
            foreach (Member::active()->inRandomOrder()->limit(6)->pluck('id') as $mid) {
                $giving->record($building, ['kind' => 'gift', 'contributor_type' => 'member', 'member_id' => $mid, 'amount' => rand(3, 15) * 100,
                    'date' => $sunday->copy()->subWeeks(2), 'payment_method' => 'Cash', 'event_id' => $event->id], $admin);
            }
            $giving->record($building, ['kind' => 'gift', 'contributor_type' => 'named', 'contributor_name' => 'Well-wisher', 'amount' => 2000,
                'date' => $sunday->copy()->subWeeks(2), 'payment_method' => 'Cash', 'event_id' => $event->id, 'hide_name' => true], $admin);
        }
    }

    private function sermons(): void
    {
        $james = \App\Models\SermonSeries::create(['name' => 'Faith that works', 'description' => 'A walk through the letter of James.']);
        $preachers = Member::active()->whereIn('name', ['Michael Asante', 'Grace Mensah', 'Samuel Boateng'])->get()->values();
        $past = [
            ['James 1:2–8', 'Joy in trials', 'Faith that works 1', $james->id],
            ['James 1:19–27', 'Doers of the word', 'Faith that works 2', $james->id],
            ['James 2:14–26', 'Faith without works is dead', 'Faith that works 3', $james->id],
            ['Psalm 23', 'The Lord is my shepherd', 'Comfort in the valley', null],
            ['John 15:1–8; Galatians 5:22–23', 'Abiding and fruitfulness', 'Abide in me', null],
            ['Luke 15:11–32', 'The Father\'s welcome', 'Coming home', null],
            ['Romans 8:28–39', 'Nothing can separate us', 'More than conquerors', null],
            ['Matthew 6:25–34', 'Trusting God with tomorrow', 'Do not worry', null],
        ];
        $sunday = now()->startOfWeek()->subDay(); // last Sunday
        foreach ($past as $i => [$refs, $theme, $title, $series]) {
            $parsed = collect(explode(';', $refs))->map(fn ($r) => \App\Support\Scripture::parse(trim($r)))->filter();
            $visiting = $i === 3;
            \App\Models\Sermon::create([
                'date' => $sunday->copy()->subWeeks($i), 'service' => $i % 2 ? 'First Service' : 'Second Service', 'status' => 'preached',
                'preacher_member_id' => $visiting ? null : $preachers[$i % max(1, $preachers->count())]?->id,
                'preacher_name' => $visiting ? 'Rt. Rev. Moses Banja' : null, 'preacher_church' => $visiting ? 'Diocese of Kampala' : null,
                'title' => $title, 'theme' => $theme, 'sermon_series_id' => $series,
                'scriptures' => $parsed->pluck('ref')->values()->all(), 'books' => $parsed->pluck('bookIndex')->unique()->values()->all(),
                'notes' => $i === 0 ? "1. Trials test and grow our faith.\n2. Ask God for wisdom.\n3. Steadfastness leads to maturity." : null,
                'recording_url' => $i < 2 ? 'https://www.youtube.com/@stjameswigua' : null,
            ]);
        }
        $next = now()->next('Sunday');
        foreach ([[0, 'Second Service', 'James 3:1–12', 'Taming the tongue', $james->id], [1, 'Second Service', 'James 4:1–10', 'Humble yourselves', $james->id], [1, 'First Service', null, 'Harvest thanksgiving', null]] as [$w, $svc, $ref, $theme, $series]) {
            $p = $ref ? \App\Support\Scripture::parse($ref) : null;
            \App\Models\Sermon::create([
                'date' => $next->copy()->addWeeks($w), 'service' => $svc, 'status' => 'planned',
                'preacher_member_id' => $preachers[$w % max(1, $preachers->count())]?->id, 'theme' => $theme, 'sermon_series_id' => $series,
                'scriptures' => $p ? [$p['ref']] : [], 'books' => $p ? [$p['bookIndex']] : [],
            ]);
        }
    }

    private function calendar(): void
    {
        $firstSunday = now()->startOfMonth();
        while (! $firstSunday->isSunday()) {
            $firstSunday->addDay();
        }
        $lastSaturday = now()->endOfMonth()->startOfDay();
        while (! $lastSaturday->isSaturday()) {
            $lastSaturday->subDay();
        }
        $choir = Ministry::where('name', 'Choir')->first();
        $youth = Ministry::where('name', 'Youth Ministry')->first();
        $women = Ministry::where('name', "Women's Ministry")->first();
        $E = fn (array $a) => \App\Models\Event::create($a);

        $E(['title' => 'Holy Communion', 'type' => 'communion', 'starts_at' => $firstSunday->copy()->setTime(9, 30), 'ends_at' => $firstSunday->copy()->setTime(11, 30),
            'venue' => 'Main church', 'repeat' => 'monthly_nth', 'description' => 'Every first Sunday at the Second Service.']);
        $E(['title' => "Mothers' Union fellowship", 'type' => 'meeting', 'starts_at' => $lastSaturday->copy()->setTime(14, 0), 'ends_at' => $lastSaturday->copy()->setTime(16, 0),
            'venue' => 'Church hall', 'repeat' => 'monthly_nth', 'ministry_id' => $women?->id, 'remind_minutes' => [1440], 'remind_channel' => 'sms']);
        $E(['title' => 'Choir practice', 'type' => 'meeting', 'starts_at' => now()->next('Thursday')->setTime(18, 0), 'ends_at' => now()->next('Thursday')->setTime(20, 0),
            'venue' => 'Main church', 'repeat' => 'weekly', 'ministry_id' => $choir?->id]);
        $E(['title' => 'Youth conference', 'type' => 'retreat', 'starts_at' => now()->addWeeks(3)->next('Friday')->setTime(9, 0),
            'ends_at' => now()->addWeeks(3)->next('Friday')->addDays(2)->setTime(17, 0), 'venue' => 'Church hall', 'ministry_id' => $youth?->id,
            'remind_minutes' => [10080, 1440], 'remind_channel' => 'both', 'description' => 'Theme: “Rooted in Christ”. Registration UGX 20,000.']);
        $E(['title' => 'Parish Church Council', 'type' => 'meeting', 'starts_at' => now()->next('Sunday')->addWeeks(1)->setTime(14, 0),
            'ends_at' => now()->next('Sunday')->addWeeks(1)->setTime(16, 0), 'venue' => 'Vestry', 'remind_minutes' => [1440], 'remind_audience' => ['type' => 'leaders'], 'remind_channel' => 'both']);
        $E(['title' => 'Harvest thanksgiving & auction', 'type' => 'fundraising', 'starts_at' => now()->next('Sunday')->addWeeks(5)->setTime(9, 0), 'all_day' => true,
            'venue' => 'Church grounds', 'remind_minutes' => [4320, 1440], 'remind_channel' => 'sms']);
        \App\Models\Observance::create(['name' => "St James' Day (patronal festival)", 'rule' => 'fixed', 'month' => 7, 'day' => 25, 'colour' => 'red',
            'note' => 'Our church\'s feast day']);
        \App\Models\Observance::create(['name' => 'Mothers\' Union Lady Day', 'rule' => 'fixed', 'month' => 3, 'day' => 25, 'colour' => 'blue']);
    }

    private function sundaySchool(): void
    {
        foreach ([['Beginners', 0, 5, 'Children\'s room'], ['Primary', 6, 8, 'Hall A'], ['Juniors', 9, 12, 'Hall B']] as [$n, $min, $max, $room]) {
            SundaySchoolClass::create(['name' => $n, 'min_age' => $min, 'max_age' => $max, 'room' => $room]);
        }
        // a few young families so the classes have pupils
        $parents = Member::active()->where('gender', 'female')->inRandomOrder()->limit(9)->get();
        foreach ($parents as $i => $parent) {
            Member::create([
                'name' => fake()->firstName().' '.last(explode(' ', $parent->name)), 'gender' => $i % 2 ? 'male' : 'female',
                'date_of_birth' => now()->subYears(rand(3, 12))->subDays(rand(0, 300)), 'date_joined' => now()->subMonths(rand(1, 30)),
                'village' => $parent->village, 'parish' => $parent->parish, 'cell_group_id' => $parent->cell_group_id,
                'next_of_kin_member_id' => $parent->id, 'next_of_kin_relationship' => 'Mother', 'status' => 'active',
            ]);
        }
        app(SundaySchoolController::class)->autoAssign();
    }

    private function positions(): void
    {
        foreach (Ministry::whereNotNull('leader_id')->get() as $m) {
            Position::create(['member_id' => $m->leader_id, 'title' => 'Chairperson', 'scope_type' => 'ministry', 'scope_id' => $m->id, 'start_date' => now()->subYears(2)]);
        }
        foreach (CellGroup::whereNotNull('leader_id')->get() as $c) {
            Position::create(['member_id' => $c->leader_id, 'title' => 'Cell leader', 'scope_type' => 'cell_group', 'scope_id' => $c->id, 'start_date' => now()->subYear()]);
        }
        $teachers = Member::active()->whereNotNull('date_of_birth')->whereDate('date_of_birth', '<', now()->subYears(20))->inRandomOrder()->limit(4)->get();
        foreach (SundaySchoolClass::orderBy('min_age')->get() as $i => $class) {
            Position::create(['member_id' => $teachers[$i]->id, 'title' => 'Sunday school teacher', 'scope_type' => 'sunday_school_class', 'scope_id' => $class->id, 'start_date' => now()->subMonths(8)]);
        }
        Position::create(['member_id' => $teachers[3]->id, 'title' => 'Church warden', 'scope_type' => 'church', 'start_date' => now()->subYears(3)]);
    }

    private function care(): void
    {
        $members = Member::active()->inRandomOrder()->limit(4)->get();
        $c = CareCase::create(['type' => 'bereavement', 'member_id' => $members[0]->id, 'subject' => 'Mzee Erisa Mukasa', 'relationship' => 'Father',
            'date' => now()->subDays(6), 'burial_date' => now()->subDays(3), 'burial_place' => 'Mukono', 'notes' => 'Church sent a delegation and a contribution.']);
        PastoralVisit::create(['care_case_id' => $c->id, 'member_id' => $members[0]->id, 'type' => 'bereavement', 'date' => now()->subDays(5),
            'place' => 'Family home', 'team' => 'Rev. Okello, Mothers\' Union', 'notes' => 'Prayed with the family.', 'gift_amount' => 200, 'follow_up_on' => now()->addDays(4)]);
        $h = CareCase::create(['type' => 'hospital', 'member_id' => $members[1]->id, 'subject' => $members[1]->name, 'date' => now()->subDays(2), 'notes' => 'Admitted at Mulago, ward 4B.']);
        PastoralVisit::create(['care_case_id' => $h->id, 'member_id' => $members[1]->id, 'type' => 'hospital', 'date' => now()->subDay(), 'place' => 'Mulago Hospital',
            'team' => 'Pastor, cell leader', 'notes' => 'Recovering well; asked for prayer for the family.', 'follow_up_on' => now()->addDays(2)]);
        CareCase::create(['type' => 'newborn', 'member_id' => $members[2]->id, 'subject' => 'Baby Esther', 'date' => now()->subDays(12), 'status' => 'open']);
        PastoralVisit::create(['member_id' => $members[3]->id, 'type' => 'home', 'date' => now()->subDays(9), 'team' => 'Cell leader', 'notes' => 'Has missed three Sundays; work shifts.']);
    }

    private function projects(): void
    {
        $p = Project::firstOrCreate(['name' => 'New church building'], ['description' => 'Phase 1: foundation and walls of the 600-seat sanctuary.', 'budget' => 150000,
            'target' => 100000, 'open_for_contributions' => true, 'status' => 'active', 'start_date' => now()->subMonths(5), 'end_date' => now()->addMonths(10)]);
        foreach ([['Building materials', 'Cement & sand (200 bags)', 18500], ['Labour', 'Masons, weeks 1–6', 12000], ['Building materials', 'Iron bars', 9800], ['Professional fees', 'Architect drawings', 6000]] as [$cat, $desc, $amt]) {
            Transaction::create(['date' => now()->subWeeks(rand(1, 18)), 'type' => 'expense', 'category' => $cat, 'project_id' => $p->id, 'amount' => $amt, 'description' => $desc, 'payment_method' => 'Bank Transfer']);
        }
        $b = Project::create(['name' => 'Community borehole', 'description' => 'Clean water point beside the church for the village.', 'budget' => 25000, 'status' => 'planning', 'start_date' => now()->addMonth()]);
        Outreach::create(['title' => 'Christmas visit to Sanyu Babies Home', 'type' => 'babies_home', 'place' => 'Sanyu Babies Home, Mengo', 'date' => now()->subWeeks(3),
            'people_reached' => 46, 'items' => 'Diapers, milk formula, clothes, 2 bags of sugar', 'team' => "Mothers' Union & Youth", 'cost' => 1800, 'project_id' => null]);
        Outreach::create(['title' => 'Ward 4B prayer & gifts', 'type' => 'hospital', 'place' => 'Mulago Hospital', 'date' => now()->subWeeks(7),
            'people_reached' => 30, 'items' => 'Soap, juice, Gospel tracts', 'team' => 'Youth Ministry', 'cost' => 450]);
        Outreach::create(['title' => 'Water survey for borehole', 'type' => 'community', 'place' => 'Kireka', 'date' => now()->subWeeks(1),
            'people_reached' => 120, 'team' => 'Men\'s Ministry', 'cost' => 300, 'project_id' => $b->id]);
    }

    /** Tick some requirements for current candidates so the checklist shows progress. */
    private function baptismRequirements(): void
    {
        $reqs = app(\App\Services\SacramentRequirements::class);
        Baptism::where('status', 'scheduled')->whereNull('requirements')->get()->each(function (Baptism $b, $i) use ($reqs) {
            $state = [];
            foreach ($reqs->checklist($b) as $j => $r) {
                if ($r['key'] !== 'class' && ($j + $i) % 3 !== 0) {
                    $state[$r['key']] = ['done' => true, 'by' => 'Parish secretary', 'at' => now()->subDays(rand(1, 20))->toDateString()]
                        + ($r['fee'] ? ['amount' => $r['fee']] : []);
                }
            }
            $b->update(['requirements' => $state]);
        });
    }

    private function attendance(): void
    {
        $services = array_slice(Setting::get('services'), 0, 3);
        foreach (range(0, 11) as $w) {
            $sunday = now()->startOfWeek()->subWeeks($w)->subDay();
            foreach ($services as $i => $service) {
                $scale = [1.0, 1.4, 0.6][$i] ?? 1;
                Attendance::create([
                    'date' => $sunday, 'service' => $service,
                    'men' => (int) round(rand(5, 9) * $scale), 'women' => (int) round(rand(7, 12) * $scale),
                    'children' => (int) round(rand(4, 9) * $scale), 'visitors' => rand(0, 3),
                ]);
            }
        }
    }

    private function marriages(): void
    {
        $officiant = 'Rev. Canon John Okello';
        Marriage::create(['groom_name' => 'Daniel Ssemanda', 'bride_name' => 'Grace Achieng', 'date' => now()->next('Saturday')->addWeeks(3),
            'place' => "St. John's Church", 'officiant' => $officiant, 'banns_read' => 1, 'counselling_done' => true]);
        Marriage::create(['groom_name' => 'Ivan Mugisha', 'bride_name' => 'Esther Nakato', 'date' => now()->next('Saturday')->addWeeks(8),
            'place' => "St. John's Church", 'officiant' => $officiant, 'banns_read' => 0]);
        foreach ([['Michael Asante', 'Abena Adomako', 5], ['Samuel Boateng', 'Mary Osei', 9], ['Kwame Agyei', 'Akosua Boakye', 14]] as [$g, $b, $months]) {
            Marriage::create([
                'status' => 'completed', 'groom_name' => $g, 'bride_name' => $b, 'date' => now()->subMonths($months)->startOfWeek()->addDays(5),
                'groom_member_id' => Member::where('name', $g)->value('id'), 'bride_member_id' => Member::where('name', $b)->value('id'),
                'place' => "St. John's Church", 'officiant' => $officiant, 'witnesses' => 'John Mensah, Sarah Osei',
                'banns_read' => 3, 'counselling_done' => true,
            ]);
        }
    }

    private function pledges(): void
    {
        $building = Project::where('name', 'New church building')->first();
        $admin = \App\Models\User::role('admin')->first();
        Member::inRandomOrder()->limit(14)->get()->each(function (Member $m, $i) use ($building, $admin) {
            $purpose = $i % 3 === 0 ? 'Harvest '.now()->year : 'New church building';
            $p = Pledge::create([
                'member_id' => $m->id, 'project_id' => $purpose === 'New church building' ? $building?->id : null, 'purpose' => $purpose,
                'amount' => rand(5, 40) * 100, 'pledge_date' => now()->subMonths(rand(3, 9)),
                'due_date' => $i % 5 === 0 ? now()->subWeeks(2) : now()->addMonths(rand(1, 6)),
            ]);
            foreach (range(1, rand(0, 3)) as $n) {
                if ($n === 0) {
                    continue;
                }
                $date = now()->subWeeks(rand(1, 12))->startOfWeek()->subDay();
                $amount = round($p->amount / rand(2, 4), -1);
                if ($p->project_id) {
                    app(\App\Services\ContributionService::class)->record($p->project, ['kind' => 'pledge_payment', 'pledge_id' => $p->id,
                        'date' => $date, 'amount' => $amount, 'payment_method' => 'Mobile Money', 'service' => 'First Service'], $admin);
                } else {
                    Offertory::create([
                        'date' => $date, 'service' => 'First Service', 'category' => 'pledge', 'amount' => $amount,
                        'payment_method' => 'Mobile Money', 'member_id' => $m->id, 'pledge_id' => $p->id, 'notes' => "Payment towards: {$purpose}",
                    ]);
                }
            }
        });
    }

    private function visitors(): void
    {
        $rows = [
            ['Peter Okot', '0772 555666', 'male', 'Kireka', 2, 'Friend', 'new', [], 'Pray for my job search'],
            ['Juliet Namubiru', '0701 333444', 'female', 'Bweyogerere', 5, 'Walked in', 'new', [], null],
            ['Brian Tumusiime', '0782 909090', 'male', 'Namugongo', 9, 'Radio', 'contacted', ['welcome_sent', 'called'], null],
            ['Annet Kyomuhendo', '0752 121212', 'female', 'Kireka', 16, 'Family', 'returning', ['welcome_sent', 'called', 'invited_back'], 'Healing for my mother'],
            ['Moses Wasswa', '0774 343434', 'male', 'Nsasa', 23, 'Crusade', 'returning', ['welcome_sent', 'called', 'visited', 'invited_back'], null],
            ['Rita Akello', '0703 565656', 'female', 'Kyaliwajjala', 40, 'Friend', 'lost', ['welcome_sent'], null],
        ];
        foreach ($rows as [$name, $phone, $gender, $village, $daysAgo, $how, $status, $steps, $prayer]) {
            $v = Visitor::create([
                'name' => $name, 'phone' => $phone, 'gender' => $gender, 'village' => $village, 'first_visit' => now()->subDays($daysAgo),
                'service' => 'Second Service', 'how_heard' => $how, 'status' => $status, 'prayer_request' => $prayer,
                ...array_fill_keys($steps, true),
            ]);
            if (in_array('called', $steps, true)) {
                $v->followUps()->create(['method' => 'call', 'note' => 'Thanked them for coming; invited to Bible study.', 'date' => now()->subDays(max(0, $daysAgo - 2))]);
            }
        }
    }

    private function welfare(): void
    {
        Transaction::create(['date' => now()->subMonths(2), 'type' => 'income', 'category' => 'Welfare', 'amount' => 6000,
            'description' => 'Welfare Sunday collection', 'payment_method' => 'Cash']);
        Transaction::create(['date' => now()->subWeeks(3), 'type' => 'income', 'category' => 'Welfare', 'amount' => 2500,
            'description' => "Mothers' Union welfare contribution", 'payment_method' => 'Mobile Money']);
        $members = Member::inRandomOrder()->limit(4)->get();
        $paid = WelfareCase::create(['member_id' => $members[0]->id, 'beneficiary' => $members[0]->name, 'category' => 'bereavement',
            'description' => 'Burial support after the loss of a parent.', 'amount_requested' => 1500, 'amount_approved' => 1200,
            'status' => 'approved', 'decided_at' => now()->subWeeks(2)]);
        $tx = Transaction::create(['date' => now()->subWeeks(2), 'type' => 'expense', 'category' => 'Welfare', 'amount' => 1200,
            'description' => "Welfare (bereavement): {$paid->beneficiary}", 'payee' => $paid->beneficiary, 'payment_method' => 'Cash']);
        $paid->update(['status' => 'paid', 'transaction_id' => $tx->id]);
        WelfareCase::create(['member_id' => $members[1]->id, 'beneficiary' => $members[1]->name, 'category' => 'medical',
            'description' => 'Hospital bill at Mulago after surgery.', 'amount_requested' => 2000, 'amount_approved' => 1500,
            'status' => 'approved', 'decided_at' => now()->subDays(3)]);
        WelfareCase::create(['member_id' => $members[2]->id, 'beneficiary' => $members[2]->name, 'category' => 'school_fees',
            'description' => 'Term III fees for two children (S.2 and P.6).', 'amount_requested' => 1800]);
        WelfareCase::create(['beneficiary' => 'Widow in Kireka (non-member)', 'category' => 'food',
            'description' => 'Food basket requested by the Mothers\' Union.', 'amount_requested' => 300]);
    }

    private function cellGroups(): void
    {
        $leaders = Member::active()->inRandomOrder()->get()->groupBy('village');
        $days = ['Tuesday', 'Wednesday', 'Thursday', 'Friday'];
        Member::active()->whereNotNull('village')->distinct()->pluck('village')->values()->each(function ($village, $i) use ($leaders, $days) {
            $leader = $leaders[$village]->first();
            $g = CellGroup::create(['name' => "{$village} Cell", 'village' => $village, 'leader_id' => $leader?->id,
                'host' => $leader ? "{$leader->name}'s home" : null, 'meeting_day' => $days[$i % 4], 'meeting_time' => '18:30']);
            Member::active()->where('village', $village)->whereNull('cell_group_id')->inRandomOrder()
                ->limit(max(1, (int) (Member::active()->where('village', $village)->count() * 0.75)))->update(['cell_group_id' => $g->id]);
        });
    }

    /** Spread existing demo offerings across the first three configured services. */
    private function assignServices(): void
    {
        $services = array_slice(Setting::get('services'), 0, 3);
        Offertory::query()->each(fn (Offertory $o) => $o->update(['service' => $services[array_rand($services)]]));
    }

    private function announcements(): void
    {
        $sunday = now()->next('Sunday');
        $rows = [
            ['burial', 'Burial of the late Mzee Yosefu Kato', 'The late Mzee Yosefu Kato (78)',
                "Mzee Kato served faithfully in the Fathers' Union for over 40 years. Prayers are held nightly at his home in Kireka from 7pm.",
                now()->addDays(4)->setTime(11, 0), 'Family home, Kireka – Kamuli Road', 'Paul Kato (son)', '0772 456789', true],
            ['wedding', 'Holy Matrimony', 'Daniel Ssemanda & Grace Achieng',
                'All are invited to witness the wedding of our Youth Ministry members. Reception follows at the church grounds.',
                $sunday->copy()->addWeeks(3)->setTime(13, 0), "St. John's Church", 'Youth Ministry desk', '0701 222333', false],
            ['introduction', 'Introduction ceremony (Kwanjula)', 'Ruth Nalwoga',
                "The Women's Ministry will accompany the family. Meet at the church at 9am for transport.",
                $sunday->copy()->addWeeks(2)->setTime(10, 0), 'Bweyogerere', 'Mary Osei', '0782 111000', false],
            ['meeting', 'Parish Church Council meeting', null,
                'All council members and ministry leaders are requested to attend. Agenda: harvest planning and the building fund.',
                $sunday->copy()->setTime(14, 0), 'Church hall', 'Parish secretary', null, false],
            ['fundraising', 'Harvest thanksgiving day', null,
                'Bring your harvest offerings in kind or cash. Auction after the second service.',
                $sunday->copy()->addWeeks(5)->setTime(9, 0), "St. John's Church", 'Harvest committee', null, true],
            ['thanksgiving', 'Thanksgiving service', 'The Boateng family',
                'Thanksgiving for the safe delivery of baby Esther. Lunch to follow.', now()->subDays(10)->setTime(10, 0),
                "St. John's Church", null, null, false],
        ];
        foreach ($rows as [$type, $title, $subject, $details, $at, $venue, $contact, $phone, $pinned]) {
            Announcement::create([
                'type' => $type, 'title' => $title, 'subject' => $subject, 'details' => $details, 'event_at' => $at,
                'venue' => $venue, 'contact_name' => $contact, 'contact_phone' => $phone, 'pinned' => $pinned,
            ]);
        }
    }

    private function baptisms(): void
    {
        $officiants = ['Rev. Canon John Okello', 'Rev. Grace Namutebi', 'Ven. Samuel Kato'];

        // Completed entries across this year and last
        foreach (range(1, 14) as $i) {
            Baptism::factory()->create([
                'kind' => $i % 5 === 0 ? 'confirmation' : 'baptism',
                'date' => now()->subDays(rand(7, 420)),
                'officiant' => $officiants[array_rand($officiants)],
                'place' => "St. John's Church",
            ]);
        }

        // A few members confirmed
        Member::inRandomOrder()->limit(3)->get()->each(fn (Member $m) => Baptism::factory()->create([
            'kind' => 'confirmation', 'member_id' => $m->id, 'name' => $m->name, 'gender' => $m->gender,
            'date_of_birth' => $m->date_of_birth, 'date' => now()->subMonths(rand(1, 8)),
            'officiant' => 'Rt. Rev. Bishop Moses Banja', 'father_name' => null, 'mother_name' => null,
        ]));

        // Upcoming candidates (baptism class)
        $nextBaptismSunday = now()->next('Sunday')->addWeeks(2);
        foreach (range(1, 6) as $i) {
            Baptism::factory()->scheduled()->create([
                'kind' => $i <= 4 ? 'baptism' : 'confirmation',
                'date' => $i <= 4 ? $nextBaptismSunday : $nextBaptismSunday->copy()->addWeeks(4),
                'officiant' => $officiants[0],
                'class_completed' => $i % 2 === 0,
            ]);
        }
    }
}
