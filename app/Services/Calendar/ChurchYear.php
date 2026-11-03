<?php

namespace App\Services\Calendar;

use App\Models\Observance;
use Illuminate\Support\Carbon;

/**
 * The church year: Anglican seasons and principal feasts computed from Easter, plus custom observances.
 * Colours follow common Anglican liturgical use.
 */
class ChurchYear
{
    public static function easter(int $year): Carbon
    {
        // Anonymous Gregorian algorithm
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    /** First Sunday of Advent: the fourth Sunday before Christmas Day. */
    public static function adventSunday(int $year): Carbon
    {
        $d = Carbon::create($year, 12, 24)->startOfDay(); // Advent 4 is the Sunday on or before Christmas Eve
        while (! $d->isSunday()) {
            $d->subDay();
        }

        return $d->subWeeks(3);
    }

    /** @return list<array{name: string, start: string, end: string, colour: string, kind: string}> seasons covering the year */
    public static function seasons(int $year): array
    {
        $e = self::easter($year);
        $ash = $e->copy()->subDays(46);
        $palm = $e->copy()->subDays(7);
        $pentecost = $e->copy()->addDays(49);
        $advent = self::adventSunday($year);
        $s = fn ($name, $start, $end, $colour) => ['name' => $name, 'start' => $start->toDateString(), 'end' => $end->toDateString(), 'colour' => $colour, 'kind' => 'season'];

        return [
            $s('Christmas', Carbon::create($year, 1, 1), Carbon::create($year, 1, 5), 'white'),
            $s('Epiphany', Carbon::create($year, 1, 6), $ash->copy()->subDay(), 'green'),
            $s('Lent', $ash, $palm->copy()->subDay(), 'purple'),
            $s('Holy Week', $palm, $e->copy()->subDay(), 'red'),
            $s('Easter', $e, $pentecost->copy()->subDay(), 'white'),
            $s('Pentecost', $pentecost, $pentecost, 'red'),
            $s('Trinity', $pentecost->copy()->addDay(), $advent->copy()->subDay(), 'green'),
            $s('Advent', $advent, Carbon::create($year, 12, 24), 'purple'),
            $s('Christmas', Carbon::create($year, 12, 25), Carbon::create($year, 12, 31), 'white'),
        ];
    }

    /** @return list<array{name: string, start: string, end: string, colour: string, kind: string, custom?: bool}> */
    public static function feasts(int $year): array
    {
        $e = self::easter($year);
        $advent = self::adventSunday($year);
        $f = fn ($name, Carbon $d, $colour) => ['name' => $name, 'start' => $d->toDateString(), 'end' => $d->toDateString(), 'colour' => $colour, 'kind' => 'feast'];
        $fixed = fn ($m, $d) => Carbon::create($year, $m, $d);

        $list = [
            $f('Epiphany', $fixed(1, 6), 'white'),
            $f('Presentation of Christ', $fixed(2, 2), 'white'),
            $f('Ash Wednesday', $e->copy()->subDays(46), 'purple'),
            $f('Mothering Sunday', $e->copy()->subDays(21), 'rose'),
            $f('Annunciation', $fixed(3, 25), 'white'),
            $f('Palm Sunday', $e->copy()->subDays(7), 'red'),
            $f('Maundy Thursday', $e->copy()->subDays(3), 'white'),
            $f('Good Friday', $e->copy()->subDays(2), 'black'),
            $f('Easter Day', $e, 'gold'),
            $f('Ascension Day', $e->copy()->addDays(39), 'white'),
            $f('Pentecost', $e->copy()->addDays(49), 'red'),
            $f('Trinity Sunday', $e->copy()->addDays(56), 'white'),
            $f('Uganda Martyrs Day', $fixed(6, 3), 'red'),
            $f('All Saints', $fixed(11, 1), 'white'),
            $f('Christ the King', $advent->copy()->subWeek(), 'white'),
            $f('Advent Sunday', $advent, 'purple'),
            $f('Christmas Day', $fixed(12, 25), 'gold'),
        ];

        foreach (Observance::all() as $o) {
            $start = $o->rule === 'easter' ? $e->copy()->addDays((int) $o->easter_offset) : Carbon::create($year, $o->month, $o->day);
            $list[] = ['name' => $o->name, 'start' => $start->toDateString(), 'end' => $start->copy()->addDays(max(1, $o->duration_days) - 1)->toDateString(),
                'colour' => $o->colour, 'kind' => 'feast', 'custom' => true, 'id' => (string) $o->id, 'note' => $o->note];
        }

        return $list;
    }

    /** The season a date falls in. */
    public static function seasonOn(Carbon $date): ?array
    {
        foreach (self::seasons($date->year) as $s) {
            if ($date->toDateString() >= $s['start'] && $date->toDateString() <= $s['end']) {
                return $s;
            }
        }

        return null;
    }
}
