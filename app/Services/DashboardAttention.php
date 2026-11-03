<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Baptism;
use App\Models\Budget;
use App\Models\CareCase;
use App\Models\Offertory;
use App\Models\PastoralVisit;
use App\Models\Pledge;
use App\Models\Reminder;
use App\Models\Sermon;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WelfareCase;
use App\Services\Accounting\Ledger;
use Carbon\Carbon;

/**
 * "Needs attention": things someone in the church office should act on, each with a count and the page
 * that resolves it. Only items the user's own permissions let them open are worked out or returned,
 * and items at zero are left out.
 */
class DashboardAttention
{
    /** @return list<array{key: string, area: string, severity: string, title: string, detail: ?string, count: int, href: string}> */
    public function for(User $user): array
    {
        $can = fn (string $p) => $user->can($p);
        $items = [];
        $add = function (string $key, string $area, string $severity, int $count, string $title, ?string $detail, string $href) use (&$items) {
            if ($count > 0) {
                $items[] = compact('key', 'area', 'severity', 'title', 'detail', 'count', 'href');
            }
        };
        $today = today();
        $lastSunday = $today->isSunday() ? $today->copy()->subWeek() : $today->copy()->previous(Carbon::SUNDAY);
        $nextSunday = $today->isSunday() ? $today->copy() : $today->copy()->next(Carbon::SUNDAY);
        $services = (array) Setting::get('services', []);

        // Items need the permission to act on them (e.g. members.manage to call a visitor), not just to view.
        // ---------------------------------------------------------------- care
        if ($can('members.manage')) {
            $n = Visitor::where('status', 'new')->where('called', false)->whereDate('first_visit', '<=', $today->copy()->subDays(2)->toDateString())->count();
            $add('visitors', 'care', 'warning', $n, $n === 1 ? 'Visitor waiting for a call' : 'Visitors waiting for a call', 'First came more than 2 days ago and nobody has called yet', '/visitors');

            $n = PastoralVisit::whereNotNull('follow_up_on')->whereDateBetween('follow_up_on', [$today->copy()->subDays(30)->toDateString(), $today->toDateString()])->count();
            $add('followups', 'care', 'warning', $n, $n === 1 ? 'Pastoral follow-up due' : 'Pastoral follow-ups due', 'From visits in the care log', '/care?tab=visits');

            $n = CareCase::where('status', 'open')->whereIn('type', ['member_death', 'bereavement', 'sickness', 'hospital'])->doesntHave('visits')->count();
            $add('unvisited', 'care', 'danger', $n, $n === 1 ? 'Care case with no visit yet' : 'Care cases with no visit yet', 'Bereavement, sickness or hospital', '/care');
        }
        if ($can('welfare.manage')) {
            $n = WelfareCase::where('status', 'pending')->count();
            $add('welfare', 'care', 'warning', $n, $n === 1 ? 'Welfare request to decide' : 'Welfare requests to decide', null, '/welfare?tab=open');
        }

        // ---------------------------------------------------------------- registers & Sundays
        if ($can('members.manage')) {
            $soon = Baptism::where('status', 'scheduled')->whereDateBetween('date', [$today->toDateString(), $today->copy()->addDays(21)->toDateString()])->get();
            $req = app(SacramentRequirements::class);
            $n = $soon->filter(fn (Baptism $b) => $req->missing($b) !== [])->count();
            $add('candidates', 'registers', 'warning', $n, $n === 1 ? 'Candidate not ready' : 'Candidates not ready', 'Requirements still outstanding, in the next 3 weeks', '/baptisms?tab=scheduled');

            $recorded = Attendance::whereDate('date', $lastSunday->toDateString())->pluck('service')->all();
            $missing = array_values(array_diff($services, $recorded));
            $add('attendance', 'registers', 'info', count($missing), 'Attendance not recorded', $lastSunday->format('j M').': '.implode(', ', $missing), '/attendance?date='.$lastSunday->toDateString());

            $planned = Sermon::whereDate('date', $nextSunday->toDateString())->pluck('service')->all();
            $missing = array_values(array_diff($services, $planned));
            $add('preachers', 'registers', 'info', count($missing), count($missing) === 1 ? 'Service without a preacher' : 'Services without a preacher', $nextSunday->format('l j M').': '.implode(', ', $missing), '/sermons');
        }

        // ---------------------------------------------------------------- money
        if ($can('finance.manage')) {
            $given = Offertory::whereDate('date', $lastSunday->toDateString())->whereNotNull('service')->distinct()->pluck('service')->all();
            $missing = array_values(array_diff($services, $given));
            $add('offertory', 'money', 'info', count($missing), 'Offertory not recorded', $lastSunday->format('j M').': '.implode(', ', $missing), '/offertory');
        }
        // oversight items: anyone who reads the books (pastor, treasurer)
        if ($can('finance.view')) {

            $overdue = Pledge::withSum('payments', 'amount')->whereNotNull('due_date')->whereDate('due_date', '<', $today->toDateString())->get()
                ->filter(fn (Pledge $p) => $p->status() === 'overdue');
            $add('pledges', 'money', 'warning', $overdue->count(), $overdue->count() === 1 ? 'Overdue pledge' : 'Overdue pledges',
                number_format($overdue->sum(fn ($p) => max(0, (float) $p->amount - (float) $p->payments_sum_amount))).' still to come in', '/pledges?status=overdue');

            $budgets = Budget::where('period', $today->format('Y-m'))->get();
            Budget::hydrateSpent($budgets);
            $over = $budgets->filter(fn (Budget $b) => $b->status() !== 'on-track');
            $add('budget', 'money', $over->contains(fn ($b) => $b->status() === 'exceeded') ? 'danger' : 'warning', $over->count(),
                $over->count() === 1 ? 'Budget line nearly used' : 'Budget lines nearly used', $over->pluck('category')->take(3)->implode(', ').' (85% or more this month)', '/budget');

            $bs = (new Ledger)->balanceSheet($today->toDateString());
            foreach ($bs['warnings'] as $i => $w) {
                $add("ledger{$i}", 'money', 'danger', 1, str_contains($w, 'overspent') ? 'Fund overspent' : 'Balance below zero', $w, '/accounting');
            }
            $unmapped = $can('finance.manage') ? collect($bs['unmapped'])->flatten()->count() : 0;
            $add('unmapped', 'money', 'info', $unmapped, $unmapped === 1 ? 'Category not linked to an account' : 'Categories not linked to an account', 'Posting to Other income / expenses until linked', '/accounts?tab=chart');
        }

        // ---------------------------------------------------------------- administration
        if ($can('sms.send')) {
            $n = Reminder::whereIn('status', ['failed', 'skipped'])->where('remind_at', '>=', now()->subDays(7))->count();
            $add('reminders', 'admin', 'warning', $n, $n === 1 ? 'Reminder not sent' : 'Reminders not sent', 'In the last 7 days', '/reminders');

            $budget = (int) Setting::get('smsMonthlyBudget', 0);
            $used = SmsMessage::where('status', 'sent')->where('created_at', '>=', now()->startOfMonth())->count();
            if ($budget > 0 && $used >= 0.8 * $budget) {
                $add('smsbudget', 'admin', $used >= $budget ? 'danger' : 'warning', 1, 'SMS budget nearly used', "{$used} of {$budget} messages this month", $can('settings.manage') ? '/settings' : '/sms');
            }
        }
        if ($can('users.manage')) {
            $n = User::where('is_active', true)->whereNull('email_verified_at')->count();
            $add('unverified', 'admin', 'info', $n, $n === 1 ? 'Staff email not confirmed' : 'Staff emails not confirmed', null, '/staff');
        }

        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $items;
    }

    /**
     * The latest Sunday (today, if it is Sunday) per service: attendance and giving, compared with the
     * Sunday before and the same Sunday a year earlier. Attendance needs members.view, giving finance.view.
     */
    public function sunday(User $user): array
    {
        $today = today();
        $day = $today->isSunday() ? $today->copy() : $today->copy()->previous(Carbon::SUNDAY);
        // nothing recorded yet today? show last week's Sunday instead
        if ($day->isToday() && ! Attendance::whereDate('date', $day->toDateString())->exists() && ! Offertory::whereDate('date', $day->toDateString())->exists()) {
            $day->subWeek();
        }
        $prev = $day->copy()->subWeek();
        $lastYear = $day->copy()->subWeeks(52);
        $people = $user->can('members.view');
        $money = $user->can('finance.view');
        $services = (array) Setting::get('services', []);

        $att = fn (Carbon $d) => $people ? Attendance::whereDate('date', $d->toDateString())->get()->keyBy('service') : collect();
        $giv = fn (Carbon $d) => $money ? Offertory::whereDate('date', $d->toDateString())->selectRaw('service, SUM(amount) as total')->groupBy('service')->pluck('total', 'service') : collect();
        [$a0, $a1, $a2] = [$att($day), $att($prev), $att($lastYear)];
        [$g0, $g1, $g2] = [$giv($day), $giv($prev), $giv($lastYear)];
        $total = fn ($rows) => $rows->sum(fn ($a) => $a->total());

        $rows = collect($services)->map(fn ($s) => [
            'service' => $s,
            'attendance' => $people ? ($a0[$s] ?? null)?->total() : null,
            'attendancePrev' => $people ? ($a1[$s] ?? null)?->total() : null,
            'visitors' => $people ? ($a0[$s]->visitors ?? null) : null,
            'giving' => $money ? round((float) ($g0[$s] ?? 0), 2) : null,
            'givingPrev' => $money ? round((float) ($g1[$s] ?? 0), 2) : null,
        ])->values();

        return [
            'date' => $day->toDateString(), 'previousDate' => $prev->toDateString(), 'lastYearDate' => $lastYear->toDateString(),
            'showAttendance' => $people, 'showGiving' => $money,
            'services' => $rows,
            'totals' => [
                'attendance' => $people ? $total($a0) : null, 'attendancePrev' => $people ? $total($a1) : null, 'attendanceLastYear' => $people ? $total($a2) : null,
                'visitors' => $people ? (int) $a0->sum('visitors') : null,
                'giving' => $money ? round((float) $g0->sum(), 2) : null, 'givingPrev' => $money ? round((float) $g1->sum(), 2) : null,
                'givingLastYear' => $money ? round((float) $g2->sum(), 2) : null,
            ],
        ];
    }
}
