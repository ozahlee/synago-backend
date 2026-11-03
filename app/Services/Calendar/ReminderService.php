<?php

namespace App\Services\Calendar;

use App\Models\Alert;
use App\Models\CellGroup;
use App\Models\Event;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\PastoralVisit;
use App\Models\Pledge;
use App\Models\Position;
use App\Models\Reminder;
use App\Models\ReminderRule;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Sms\SmsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Creates automatic reminders (rules + event reminders) and sends the ones that are due,
 * honouring quiet hours and the monthly SMS budget. Run every few minutes by `reminders:run`.
 */
class ReminderService
{
    public function __construct(private SmsService $sms) {}

    public static function now(): Carbon
    {
        // Local wall-clock "now" as a naive datetime, matching how reminder times are stored.
        return Carbon::parse(now(config('church.timezone'))->format('Y-m-d H:i:s'));
    }

    /** @return array{created: int, sent: int, skipped: int, postponed: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= self::now();
        $created = $this->scheduleRules($now) + $this->scheduleEvents($now);
        [$sent, $skipped, $postponed] = $this->dispatchDue($now);

        return compact('created', 'sent', 'skipped', 'postponed');
    }

    // ---------------------------------------------------------------- scheduling

    private function scheduleRules(Carbon $now): int
    {
        $n = 0;
        $today = $now->copy()->startOfDay();
        $at = fn (array $o) => $today->copy()->setTimeFromTimeString($o['time'] ?? '08:00');

        $r = ReminderRule::resolved('service_eve');
        if ($r['enabled'] && $today->isSaturday()) {
            $services = collect(Setting::get('services', []))->map(fn ($s, $i) => isset(Setting::get('serviceTimes', [])[$i]) ? "{$s} ".Setting::get('serviceTimes')[$i] : $s)->implode(', ');
            $n += $this->create("rule:service_eve:{$today->toDateString()}", $at($r['options']), $r['options']['channel'], $r['options']['audience'],
                str_replace('{services}', $services, $r['options']['message']), 'rule:service_eve');
        }

        $r = ReminderRule::resolved('birthdays');
        if ($r['enabled']) {
            $ids = Member::active()->whereNotNull('date_of_birth')->get(['id', 'date_of_birth', 'name'])
                ->filter(fn ($m) => $m->date_of_birth->format('m-d') === $today->format('m-d'));
            if ($ids->isNotEmpty()) {
                $n += $this->create("rule:birthdays:{$today->toDateString()}", $at($r['options']), $r['options']['channel'],
                    ['type' => 'members', 'memberIds' => $ids->pluck('id')->all()], $r['options']['message'], 'rule:birthdays');
                $n += $this->create("rule:birthdays-staff:{$today->toDateString()}", $at($r['options']), 'app', ['type' => 'staff'],
                    'Birthdays today: '.$ids->pluck('name')->implode(', '), 'rule:birthdays');
            }
        }

        $r = ReminderRule::resolved('group_meetings');
        if ($r['enabled']) {
            $tomorrow = $today->copy()->addDay();
            $day = $tomorrow->format('l');
            foreach ([['cell_group', CellGroup::query()], ['ministry', Ministry::where('status', 'active')]] as [$type, $q]) {
                foreach ($q->whereRaw('LOWER(meeting_day) = ?', [strtolower($day)])->get() as $g) {
                    $msg = strtr($r['options']['message'], [
                        '{group}' => $g->name, '{day}' => $day, '{time}' => $g->meeting_time ?? 'the usual time',
                        '{place}' => ($g->host ?? $g->location ?? null) ? ' at '.($g->host ?? $g->location) : '',
                    ]);
                    $n += $this->create("rule:group:{$type}:{$g->id}:{$tomorrow->toDateString()}", $at($r['options']), $r['options']['channel'],
                        [$type === 'cell_group' ? 'cellGroupId' : 'ministryId' => $g->id, 'type' => $type], $msg, 'rule:group_meetings');
                }
            }
        }

        $r = ReminderRule::resolved('pledge_due');
        if ($r['enabled']) {
            $due = $today->copy()->addDays((int) ($r['options']['daysBefore'] ?? 7));
            Pledge::with('member')->withSum('payments', 'amount')->whereNotNull('member_id')->whereDate('due_date', $due->toDateString())->get()
                ->reject(fn ($p) => $p->status() === 'fulfilled')
                ->each(function (Pledge $p) use (&$n, $r, $at, $due) {
                    $msg = strtr($r['options']['message'], [
                        '{purpose}' => $p->purpose, '{date}' => $due->format('j M Y'),
                        '{balance}' => number_format(max(0, (float) $p->amount - $p->paid())),
                    ]);
                    $n += $this->create("rule:pledge:{$p->id}:{$due->toDateString()}", $at($r['options']), $r['options']['channel'],
                        ['type' => 'members', 'memberIds' => [$p->member_id]], $msg, 'rule:pledge_due', $p->id);
                });
        }

        $r = ReminderRule::resolved('preacher_rota');
        if ($r['enabled']) {
            $on = $today->copy()->addDays((int) ($r['options']['daysBefore'] ?? 3));
            \App\Models\Sermon::where('status', 'planned')->whereNotNull('preacher_member_id')->whereDate('date', $on->toDateString())->get()
                ->each(function (\App\Models\Sermon $s) use (&$n, $r, $at, $on) {
                    $msg = strtr($r['options']['message'], [
                        '{service}' => $s->service ?? 'service', '{date}' => $on->format('l j M'),
                        '{theme}' => $s->theme ? " (theme: {$s->theme})" : '',
                        '{text}' => $s->scriptures ? ', reading '.implode('; ', $s->scriptures) : '',
                    ]);
                    $n += $this->create("rule:preacher:{$s->id}:{$on->toDateString()}", $at($r['options']), $r['options']['channel'],
                        ['type' => 'members', 'memberIds' => [$s->preacher_member_id]], $msg, 'rule:preacher_rota', $s->id);
                });
        }

        $r = ReminderRule::resolved('follow_ups');
        if ($r['enabled']) {
            PastoralVisit::with('member')->whereDate('follow_up_on', $today->toDateString())->get()
                ->each(function (PastoralVisit $v) use (&$n, $r, $at, $today) {
                    $users = $v->recorded_by ? [$v->recorded_by] : User::permission('members.manage')->pluck('id')->all();
                    $n += $this->create("rule:followup:{$v->id}:{$today->toDateString()}", $at($r['options']), 'app',
                        ['type' => 'users', 'userIds' => $users], "Follow-up visit due today: {$v->visiteeLabel()} ({$v->type} visit on {$v->date->format('j M')}).",
                        'rule:follow_ups', $v->id);
                });
        }

        $r = ReminderRule::resolved('week_ahead');
        if ($r['enabled'] && $today->isMonday()) {
            $events = app(CalendarService::class)->items(User::role('admin')->first() ?? new User, $today, $today->copy()->addDays(6)->endOfDay(),
                ['event', 'announcement', 'sacrament'])->map(fn ($i) => Carbon::parse($i['start'])->format('D').": {$i['title']}");
            if ($events->isNotEmpty()) {
                $n += $this->create("rule:week:{$today->toDateString()}", $at($r['options']), 'app', ['type' => 'staff'],
                    "This week:\n".$events->take(15)->implode("\n"), 'rule:week_ahead');
            }
        }

        return $n;
    }

    /** Event reminders for occurrences in the next three days. */
    private function scheduleEvents(Carbon $now): int
    {
        $n = 0;
        $horizon = $now->copy()->addDays(3);
        Event::whereNotNull('remind_minutes')->where('starts_at', '<=', $horizon)->get()->each(function (Event $e) use (&$n, $now, $horizon) {
            foreach ($e->occurrences($now, $horizon) as $start) {
                foreach ($e->remind_minutes ?? [] as $mins) {
                    $at = $start->copy()->subMinutes((int) $mins);
                    if ($at->lt($now->copy()->subMinutes(10))) {
                        continue; // too late for this one
                    }
                    $when = $e->all_day ? $start->format('l j M') : $start->format('l j M, g:ia');
                    $msg = "{church}: {$e->title}, {$when}".($e->venue ? " at {$e->venue}" : '').'.';
                    $n += $this->create("event:{$e->id}:{$start->format('YmdHi')}:{$mins}", $at, $e->remind_channel,
                        $e->remind_audience ?: $this->eventAudience($e), $msg, 'event', $e->id);
                }
            }
        });

        return $n;
    }

    public function eventAudience(Event $e): array
    {
        return match (true) {
            (bool) $e->cell_group_id => ['type' => 'cell_group', 'cellGroupId' => $e->cell_group_id],
            (bool) $e->ministry_id => ['type' => 'ministry', 'ministryId' => $e->ministry_id],
            default => ['type' => 'all'],
        };
    }

    public function create(string $key, Carbon $at, string $channel, array $audience, string $message, string $source, ?int $sourceId = null, ?int $by = null): int
    {
        try {
            Reminder::create([
                'dedupe_key' => $key, 'remind_at' => $at, 'channel' => $channel, 'audience' => $audience,
                'audience_label' => $this->label($audience), 'message' => $message, 'source' => $source,
                'source_id' => $sourceId, 'created_by' => $by,
            ]);

            return 1;
        } catch (QueryException) {
            return 0; // already scheduled (dedupe_key unique)
        }
    }

    // ---------------------------------------------------------------- sending

    /** @return array{0: int, 1: int, 2: int} sent, skipped, postponed */
    private function dispatchDue(Carbon $now): array
    {
        $sent = $skipped = $postponed = 0;
        $due = Reminder::with('creator')->where('status', 'pending')->where('remind_at', '<=', $now)->orderBy('remind_at')->limit(50)->get();

        foreach ($due as $r) {
            if ($r->remind_at->lt($now->copy()->subDay())) {
                $r->update(['status' => 'skipped', 'note' => 'Missed by more than a day (was the scheduler off?)']);
                $skipped++;

                continue;
            }
            $usesSms = in_array($r->channel, ['sms', 'both'], true);
            if ($usesSms && $this->inQuietHours($now)) {
                $r->update(['remind_at' => $this->quietEnd($now), 'note' => 'Held back for quiet hours']);
                $postponed++;

                continue;
            }
            if ($usesSms) {
                $count = $this->sms->resolve($r->audience)['recipients']->count();
                $budget = (int) Setting::get('smsMonthlyBudget', 0);
                $used = SmsMessage::where('status', 'sent')->where('created_at', '>=', now()->startOfMonth())->count();
                if ($budget > 0 && $used + $count > $budget) {
                    $r->update(['status' => 'skipped', 'note' => "Monthly SMS budget reached ({$used}/{$budget})"]);
                    Alert::create(['title' => 'SMS reminder not sent', 'type' => 'warning', 'important' => true,
                        'message' => "“{$r->audience_label}” reminder was skipped: this month's SMS budget ({$budget}) would be exceeded. Raise it in Church settings."]);
                    $skipped++;

                    continue;
                }
            }

            $total = 0;
            if ($usesSms) {
                $creator = $r->creator ?? User::role('admin')->first();
                $batch = $this->sms->send($r->audience, $r->message, $creator);
                $r->sms_batch_id = $batch->id;
                $total += $batch->sent_count;
            }
            if (in_array($r->channel, ['app', 'both'], true)) {
                $total += $this->notify($r);
            }
            $r->update(['status' => 'sent', 'sent_count' => $total]);
            $sent++;
        }

        return [$sent, $skipped, $postponed];
    }

    /** In-app notifications to the staff accounts behind an audience. */
    private function notify(Reminder $r): int
    {
        $a = $r->audience;
        $memberUsers = fn (Collection $memberIds) => User::where('is_active', true)->whereIn('member_id', $memberIds)->pluck('id');
        $ids = match ($a['type'] ?? 'staff') {
            'users' => collect($a['userIds'] ?? []),
            'staff', 'all' => User::where('is_active', true)->pluck('id'),
            'leaders' => $memberUsers(Position::current()->pluck('member_id')),
            'ministry' => $memberUsers(Member::whereRelation('ministries', 'ministries.id', $a['ministryId'] ?? 0)->pluck('id')),
            'cell_group' => $memberUsers(Member::where('cell_group_id', $a['cellGroupId'] ?? 0)->pluck('id')),
            'members' => $memberUsers(collect($a['memberIds'] ?? [])),
            default => collect(),
        };
        $title = str_starts_with((string) $r->source, 'rule:') ? ReminderRule::DEFAULTS[substr($r->source, 5)]['label'] ?? 'Reminder' : 'Reminder';
        foreach ($ids->unique() as $uid) {
            Alert::create(['user_id' => $uid, 'title' => $title, 'message' => str_replace('{church}', Setting::get('churchName'), str_replace('{name}', 'you', $r->message)), 'type' => 'info']);
        }

        return $ids->unique()->count();
    }

    public function inQuietHours(Carbon $t): bool
    {
        $start = Setting::get('quietHoursStart', '20:00');
        $end = Setting::get('quietHoursEnd', '07:00');
        $hm = $t->format('H:i');

        return $start > $end ? ($hm >= $start || $hm < $end) : ($hm >= $start && $hm < $end);
    }

    private function quietEnd(Carbon $t): Carbon
    {
        $end = $t->copy()->setTimeFromTimeString(Setting::get('quietHoursEnd', '07:00'));

        return $end->lte($t) ? $end->addDay() : $end;
    }

    public function label(array $a): string
    {
        return match ($a['type'] ?? '') {
            'all' => 'All active members',
            'staff' => 'Church staff',
            'leaders' => 'Church leaders',
            'ministry' => (Ministry::find($a['ministryId'] ?? 0)?->name ?? 'Ministry').' ministry',
            'cell_group' => CellGroup::find($a['cellGroupId'] ?? 0)?->name ?? 'Cell group',
            'members' => count($a['memberIds'] ?? []) === 1 ? (Member::find($a['memberIds'][0])?->name ?? 'A member') : count($a['memberIds'] ?? []).' members',
            'users' => count($a['userIds'] ?? []) === 1 ? (User::find($a['userIds'][0])?->name ?? 'Staff member') : count($a['userIds'] ?? []).' staff',
            'gender' => ($a['gender'] ?? '') === 'female' ? 'Women' : 'Men',
            'visitors' => 'Recent visitors',
            'numbers' => 'Typed numbers',
            default => 'Everyone',
        };
    }
}
