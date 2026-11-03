<?php

namespace App\Services\Calendar;

use App\Models\Announcement;
use App\Models\Baptism;
use App\Models\CellGroup;
use App\Models\Event;
use App\Models\Marriage;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\MinistryActivity;
use App\Models\Outreach;
use App\Models\PastoralVisit;
use App\Models\Pledge;
use App\Models\Setting;
use App\Models\User;
use App\Support\Leadership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One calendar from everything the church already records, scoped to what the user may see.
 * Items: {id, kind, title, start, end, allDay, subtitle, link: {type, id}}
 */
class CalendarService
{
    private const DAYS = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];

    public function items(User $user, Carbon $from, Carbon $to, ?array $kinds = null): Collection
    {
        $want = fn (string $k) => $kinds === null || in_array($k, $kinds, true);
        $broad = $user->can('members.view');
        $scopes = Leadership::scopes($user);
        $items = collect();

        if ($want('event')) {
            Event::with(['ministry', 'cellGroup'])
                ->where('starts_at', '<=', $to)
                ->where(fn ($q) => $q->where('repeat', '!=', 'none')->orWhere('starts_at', '>=', $from->copy()->subDays(7)))
                ->get()
                ->each(function (Event $e) use ($from, $to, $items) {
                    foreach ($e->occurrences($from, $to) as $start) {
                        $items->push($this->item("event-{$e->id}-{$start->format('YmdHi')}", 'event', $e->title, $start,
                            $start->copy()->addMinutes($e->durationMinutes()), $e->all_day,
                            collect([$e->venue, $e->ministry?->name, $e->cellGroup?->name])->filter()->implode(' · '),
                            ['type' => 'event', 'id' => (string) $e->id], ['eventType' => $e->type, 'repeats' => $e->repeat !== 'none']));
                    }
                });
        }

        if ($want('service')) {
            $services = Setting::get('services', []);
            $times = Setting::get('serviceTimes', []);
            for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
                if (! $d->isSunday()) {
                    continue;
                }
                foreach ($services as $i => $name) {
                    $time = $times[$i] ?? null;
                    $start = $time ? $d->copy()->setTimeFromTimeString($time) : $d->copy();
                    $items->push($this->item("service-{$d->toDateString()}-{$i}", 'service', $name, $start, $time ? $start->copy()->addMinutes(105) : null, ! $time, null, null));
                }
            }
        }

        // Weekly cell and ministry meetings (leaders see their own groups)
        if ($want('meeting')) {
            $cells = CellGroup::whereNotNull('meeting_day')
                ->when(! $broad, fn ($q) => $q->whereIn('id', $scopes['cell_group'] ?? [0]))->get();
            $ministries = Ministry::where('status', 'active')->whereNotNull('meeting_day')
                ->when(! $broad, fn ($q) => $q->whereIn('id', $scopes['ministry'] ?? [0]))->get();
            foreach ([['cell_group', $cells, 'Cell'], ['ministry', $ministries, 'Ministry']] as [$type, $groups, $label]) {
                foreach ($groups as $g) {
                    $dow = self::DAYS[strtolower($g->meeting_day)] ?? null;
                    if ($dow === null) {
                        continue;
                    }
                    for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
                        if ($d->dayOfWeek !== $dow) {
                            continue;
                        }
                        $start = $g->meeting_time ? $d->copy()->setTimeFromTimeString($g->meeting_time) : $d->copy();
                        $items->push($this->item("{$type}-{$g->id}-{$d->toDateString()}", 'meeting', $g->name, $start,
                            $g->meeting_time ? $start->copy()->addMinutes(90) : null, ! $g->meeting_time,
                            $type === 'cell_group' ? ($g->host ?? $g->location ?? "{$label} meeting") : ($g->location ?? "{$label} meeting"),
                            ['type' => $type === 'cell_group' ? 'cellGroup' : 'ministry', 'id' => (string) $g->id]));
                    }
                }
            }
        }

        if ($broad && $want('activity')) {
            MinistryActivity::with('ministry')->whereBetween('scheduled_at', [$from, $to])->get()
                ->each(fn ($a) => $items->push($this->item("activity-{$a->id}", 'activity', $a->title, $a->scheduled_at, null, false,
                    $a->ministry?->name, ['type' => 'ministry', 'id' => (string) $a->ministry_id])));
        }

        if ($want('announcement')) {
            Announcement::whereBetween('event_at', [$from, $to])->get()
                ->each(fn ($a) => $items->push($this->item("announcement-{$a->id}", $a->type === 'burial' ? 'burial' : ($a->type === 'wedding' ? 'wedding' : 'announcement'),
                    $a->title, $a->event_at, null, false, collect([$a->subject, $a->venue])->filter()->implode(' · '),
                    ['type' => 'announcement', 'id' => (string) $a->id])));
        }

        if ($broad && $want('sacrament')) {
            Baptism::where('status', 'scheduled')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()
                ->groupBy(fn ($b) => $b->date->toDateString().'|'.$b->kind)
                ->each(function ($g, $key) use ($items) {
                    [$date, $kind] = explode('|', $key);
                    $items->push($this->item("sacrament-{$key}", 'sacrament', ucfirst($kind).($g->count() > 1 ? "s ({$g->count()})" : ": {$g->first()->name}"),
                        Carbon::parse($date), null, true, $g->pluck('name')->take(6)->implode(', '), ['type' => 'baptism', 'id' => (string) $g->first()->id, 'tab' => 'scheduled']));
                });
            Marriage::where('status', 'scheduled')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()
                ->each(fn ($m) => $items->push($this->item("marriage-{$m->id}", 'wedding', "Wedding: {$m->groom_name} & {$m->bride_name}", $m->date, null, true,
                    $m->place, ['type' => 'marriage', 'id' => (string) $m->id, 'tab' => 'scheduled'])));
        }

        if ($broad && $want('birthday')) {
            Member::active()->whereNotNull('date_of_birth')->get(['id', 'name', 'date_of_birth'])
                ->each(function (Member $m) use ($from, $to, $items) {
                    foreach (range($from->year, $to->year) as $y) {
                        $d = Carbon::create($y, $m->date_of_birth->month, min($m->date_of_birth->day, Carbon::create($y, $m->date_of_birth->month)->daysInMonth));
                        if ($d->betweenIncluded($from->copy()->startOfDay(), $to)) {
                            $items->push($this->item("birthday-{$m->id}-{$y}", 'birthday', $m->name, $d, null, true,
                                'Turns '.($y - $m->date_of_birth->year), ['type' => 'member', 'id' => (string) $m->id]));
                        }
                    }
                });
        }

        if ($broad && $want('sermon')) {
            $svcTimes = Setting::get('serviceTimes', []);
            $times = collect(Setting::get('services', []))->mapWithKeys(fn ($name, $i) => [$name => $svcTimes[$i] ?? null])->all();
            \App\Models\Sermon::with('preacher')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get()
                ->each(function (\App\Models\Sermon $s) use ($items, $times) {
                    $time = $s->service ? ($times[$s->service] ?? null) : null;
                    $start = $time ? Carbon::parse($s->date->toDateString().' '.$time) : $s->date->copy();
                    $items->push($this->item("sermon-{$s->id}", 'sermon', 'Preaching: '.$s->preacherLabel(), $start, null, ! $time,
                        collect([$s->service, $s->theme ?? $s->title, ($s->scriptures ?? [])[0] ?? null])->filter()->implode(' · '),
                        ['type' => 'sermon', 'id' => (string) $s->id]));
                });
        }

        if ($user->can('finance.view') && $want('pledge')) {
            Pledge::with('member')->withSum('payments', 'amount')->whereDate('due_date', '>=', $from->toDateString())->whereDate('due_date', '<=', $to->toDateString())->get()
                ->reject(fn ($p) => $p->status() === 'fulfilled')
                ->each(fn ($p) => $items->push($this->item("pledge-{$p->id}", 'pledge', "Pledge due: {$p->pledgerLabel()}", $p->due_date, null, true,
                    $p->purpose, ['type' => 'pledge', 'id' => (string) $p->id])));
        }

        if ($broad && $want('care')) {
            PastoralVisit::with('member')->whereDate('follow_up_on', '>=', $from->toDateString())->whereDate('follow_up_on', '<=', $to->toDateString())->get()
                ->each(fn ($v) => $items->push($this->item("followup-{$v->id}", 'care', "Follow up: {$v->visiteeLabel()}", $v->follow_up_on, null, true,
                    ucfirst($v->type).' visit', ['type' => 'care', 'id' => (string) $v->id])));
            Outreach::whereDateBetween('date', [$from->toDateString(), $to->toDateString()])->get()
                ->each(fn ($o) => $items->push($this->item("outreach-{$o->id}", 'outreach', $o->title, $o->date, null, true, $o->place, ['type' => 'outreach', 'id' => (string) $o->id])));
        }

        return $items->sortBy('start')->values();
    }

    private function item(string $id, string $kind, string $title, Carbon $start, ?Carbon $end, bool $allDay, ?string $subtitle, ?array $link, array $extra = []): array
    {
        return [
            'id' => $id, 'kind' => $kind, 'title' => $title,
            'start' => $allDay ? $start->toDateString() : $start->format('Y-m-d\TH:i'),
            'end' => $end ? ($allDay ? $end->toDateString() : $end->format('Y-m-d\TH:i')) : null,
            'allDay' => $allDay, 'subtitle' => $subtitle ?: null, 'link' => $link,
        ] + $extra;
    }
}
