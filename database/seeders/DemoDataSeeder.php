<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Budget;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Offertory;
use App\Models\Transaction;
use Illuminate\Database\Seeder;

/** Sample data mirroring the dashboard mocks; dates are relative to today so charts look alive. */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Member::exists()) {
            return; // don't duplicate on re-seed
        }

        $members = collect([
            ['John Mensah', 'male', 'Youth Ministry', true],
            ['Sarah Osei', 'female', 'Choir', true],
            ['Emmanuel Boateng', 'male', 'Ushering', false],
            ['Grace Owusu', 'female', "Women's Ministry", false],
            ['Michael Asante', 'male', "Men's Ministry", true],
            ['Akosua Boakye', 'female', 'Choir', false],
            ['Kwame Agyei', 'male', "Men's Ministry", false],
            ['Abena Adomako', 'female', "Women's Ministry", true],
            ['Mary Osei', 'female', "Women's Ministry", false],
            ['Samuel Boateng', 'male', "Men's Ministry", false],
            ['Michael Thompson', 'male', 'Youth Ministry', false],
            ['Grace Mensah', 'female', 'Choir', false],
        ])->map(fn ($row) => [
            'member' => Member::factory()->create([
                'name' => $row[0],
                'email' => strtolower(str_replace(' ', '.', $row[0])).'@example.com',
                'gender' => $row[1],
                'status' => 'active',
                'sunday_school' => $row[3],
            ]),
            'ministry' => $row[2],
        ]);
        Member::factory()->count(60)->create();

        $leaders = ['Youth Ministry' => 'Michael Thompson', 'Choir' => 'Grace Mensah', "Women's Ministry" => 'Mary Osei',
            "Men's Ministry" => 'Samuel Boateng', 'Ushering' => 'Emmanuel Boateng'];
        $details = [
            'Youth Ministry' => ['Empowering young people to grow in faith and serve the community', 'Saturday', '16:00'],
            'Choir' => ['Leading worship through music and song every Sunday', 'Thursday', '18:00'],
            "Women's Ministry" => ['Supporting and encouraging women in their spiritual journey', 'Tuesday', '17:30'],
            "Men's Ministry" => ['Building strong Christian men who lead with integrity', 'Friday', '19:00'],
            'Ushering' => ['Welcoming worshippers and keeping services orderly', 'Sunday', '08:00'],
        ];

        $ministries = collect($details)->map(fn ($d, $name) => Ministry::create([
            'name' => $name,
            'description' => $d[0],
            'meeting_day' => $d[1],
            'meeting_time' => $d[2],
            'leader_id' => $members->firstWhere('member.name', $leaders[$name])['member']->id,
        ]));

        foreach ($members as $row) {
            $row['member']->ministries()->attach($ministries[$row['ministry']]->id, ['is_primary' => true, 'joined_at' => now()]);
        }
        $ministries['Youth Ministry']->activities()->createMany([
            ['title' => 'Weekly Bible Study', 'scheduled_at' => now()->next('Saturday')->setTime(16, 0)],
            ['title' => 'Youth Conference', 'scheduled_at' => now()->addMonth()],
        ]);

        // Offertory over the last 6 months
        $memberIds = Member::pluck('id');
        foreach (range(0, 25) as $week) {
            $sunday = now()->startOfWeek()->subWeeks($week)->subDay();
            foreach (Offertory::CATEGORIES as $category) {
                Offertory::factory()->count(rand(1, 3))->create([
                    'date' => $sunday,
                    'service' => fake()->randomElement(['First Service', 'Second Service', 'Third Service']),
                    'category' => $category,
                    'member_id' => $memberIds->random(),
                    'contributor_name' => null,
                ]);
            }
        }

        // Accounts + budgets for the current month
        $categories = ['Utilities' => 5000, 'Salaries' => 15000, 'Ministry Programs' => 8000,
            'Maintenance' => 4000, 'Outreach' => 6000, 'Media & Technology' => 3000];
        foreach ($categories as $category => $allocated) {
            Budget::create(['category' => $category, 'allocated' => $allocated, 'period' => now()->format('Y-m')]);
            Transaction::factory()->count(2)->expense($category)->create([
                'date' => now()->startOfMonth()->addDays(rand(0, max(0, now()->day - 1))),
                'amount' => round($allocated * rand(20, 55) / 100, 2),
            ]);
        }
        Transaction::factory()->count(30)->create();

        // Projects open for contributions
        $admin = \App\Models\User::role('admin')->first();
        $giving = app(\App\Services\ContributionService::class);
        foreach ([
            ['New church building', 'Phase 1: foundation and walls of the 600-seat sanctuary.', 150000, 100000],
            ['Mission Trip', "Supporting our missionary team's trip to rural communities", 25000, 25000],
            ['Community Outreach', 'Food and clothing drive for underprivileged families', 10000, 10000],
        ] as [$name, $description, $budget, $target]) {
            $project = \App\Models\Project::create(['name' => $name, 'description' => $description, 'budget' => $budget, 'target' => $target,
                'status' => 'active', 'open_for_contributions' => true, 'start_date' => now()->subMonths(5), 'deadline' => now()->addMonths(rand(2, 10))]);
            foreach (range(1, rand(5, 12)) as $_) {
                $giving->record($project, ['kind' => 'gift', 'contributor_type' => 'member', 'member_id' => $memberIds->random(),
                    'amount' => rand(50, 2500), 'date' => now()->subDays(rand(0, 90)), 'payment_method' => 'Mobile Money'], $admin);
            }
        }

        Alert::insert([
            ['title' => 'Budget Alert', 'message' => 'Ministry Programs budget is nearing its limit', 'type' => 'warning', 'important' => true, 'read' => false, 'archived' => false, 'created_at' => now()->subHours(3), 'updated_at' => now()],
            ['title' => 'Upcoming Event', 'message' => 'Youth Conference scheduled next month', 'type' => 'info', 'important' => false, 'read' => true, 'archived' => false, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);
    }
}
