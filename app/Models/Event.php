<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A church calendar event, optionally repeating. Times are local wall-clock (config church.timezone). */
class Event extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['service', 'communion', 'meeting', 'fundraising', 'crusade', 'retreat', 'celebration', 'other'];

    public const REPEATS = ['none', 'weekly', 'monthly_nth', 'monthly_day', 'yearly'];

    protected $attributes = ['type' => 'meeting', 'repeat' => 'none', 'all_day' => false, 'remind_channel' => 'sms'];

    protected $fillable = [
        'title', 'type', 'description', 'starts_at', 'ends_at', 'all_day', 'venue', 'organiser_member_id', 'ministry_id',
        'cell_group_id', 'project_id', 'repeat', 'repeat_until', 'remind_minutes', 'remind_audience', 'remind_channel', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean', 'repeat_until' => 'date',
            'remind_minutes' => 'array', 'remind_audience' => 'array',
        ];
    }

    public function organiser(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'organiser_member_id')->withTrashed();
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }

    public function cellGroup(): BelongsTo
    {
        return $this->belongsTo(CellGroup::class);
    }

    public function durationMinutes(): int
    {
        return $this->ends_at ? max(0, (int) $this->starts_at->diffInMinutes($this->ends_at)) : ($this->all_day ? 1440 : 120);
    }

    /**
     * Start times of every occurrence that overlaps [from, to].
     *
     * @return list<Carbon>
     */
    public function occurrences(Carbon $from, Carbon $to): array
    {
        $start = $this->starts_at->copy();
        $until = $this->repeat_until ? $this->repeat_until->copy()->endOfDay() : null;
        $end = $until && $until->lt($to) ? $until : $to;
        $dur = $this->durationMinutes();
        $out = [];

        if ($this->repeat === 'none') {
            return $start->copy()->addMinutes($dur)->gte($from) && $start->lte($to) ? [$start] : [];
        }

        // monthly "nth weekday": 1st..4th, or "last" when the first date fell in week 5
        $nth = (int) ceil($start->day / 7);
        $weekday = $start->dayOfWeek;

        for ($i = 0, $cur = $start->copy(); $i < 1000 && $cur->lte($end); $i++) {
            if ($cur->copy()->addMinutes($dur)->gte($from)) {
                $out[] = $cur->copy();
            }
            $cur = match ($this->repeat) {
                'weekly' => $start->copy()->addWeeks($i + 1),
                'monthly_day' => $start->copy()->addMonthsNoOverflow($i + 1),
                'yearly' => $start->copy()->addYearsNoOverflow($i + 1),
                'monthly_nth' => $this->nthWeekday($start->copy()->startOfMonth()->addMonths($i + 1), $nth, $weekday)
                    ->setTime($start->hour, $start->minute),
                default => $end->copy()->addDay(),
            };
        }

        return $out;
    }

    private function nthWeekday(Carbon $monthStart, int $nth, int $weekday): Carbon
    {
        if ($nth >= 5) { // "last <weekday> of the month"
            $d = $monthStart->copy()->endOfMonth()->startOfDay();
            while ($d->dayOfWeek !== $weekday) {
                $d->subDay();
            }

            return $d;
        }
        $d = $monthStart->copy()->startOfDay();
        while ($d->dayOfWeek !== $weekday) {
            $d->addDay();
        }

        return $d->addWeeks($nth - 1);
    }
}
