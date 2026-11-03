<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Pledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Tithe register for a year: one row per tither (members by pledge number, visitors by name)
     * with 12 monthly totals, plus month totals and the grand total.
     */
    public function tithes(Request $request): JsonResponse
    {
        [$from, $to, $year] = $this->reportRange($request);
        // one column per calendar month in the period
        $months = [];
        for ($m = \Carbon\Carbon::parse($from)->startOfMonth(); $m->lte(\Carbon\Carbon::parse($to)); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }
        $multiYear = substr($from, 0, 4) !== substr($to, 0, 4);
        $index = array_flip($months);

        $records = Offertory::with('member')
            ->where('category', 'tithe')
            ->whereDateBetween('date', [$from, $to])
            ->get(['id', 'date', 'amount', 'member_id', 'contributor_name']);

        $rows = $records
            ->groupBy(fn (Offertory $o) => $o->member_id ? "m{$o->member_id}" : 'n'.mb_strtolower($o->contributor_name ?? 'anonymous'))
            ->map(function ($gifts) use ($index) {
                /** @var Offertory $first */
                $first = $gifts->first();
                $months = array_fill(0, count($index), 0.0);
                foreach ($gifts as $g) {
                    $months[$index[$g->date->format('Y-m')]] += (float) $g->amount;
                }

                return [
                    'memberId' => $first->member_id ? (string) $first->member_id : null,
                    'name' => $first->contributorLabel(),
                    'pledgeNumber' => $first->member?->pledge_number,
                    'months' => array_map(fn ($v) => round($v, 2), $months),
                    'total' => round(array_sum($months), 2),
                    'count' => $gifts->count(),
                    'monthsGiven' => count(array_filter($months)),
                ];
            })
            ->sortBy([['pledgeNumber', 'asc'], ['name', 'asc']])
            ->values();

        $monthTotals = array_map(
            fn ($i) => round($rows->sum(fn ($r) => $r['months'][$i]), 2),
            array_keys($months),
        );

        return response()->json(['data' => [
            'year' => $year, 'from' => $from, 'to' => $to,
            'months' => array_map(fn ($k) => ['key' => $k, 'label' => \Carbon\Carbon::parse("{$k}-01")->format($multiYear ? 'M Y' : 'M')], $months),
            'monthTotals' => $monthTotals,
            'total' => round(array_sum($monthTotals), 2),
            'tithers' => $rows->count(),
            'rows' => $rows,
        ]]);
    }

    /** One member's giving statement for a year. */
    public function statement(Request $request): JsonResponse
    {
        $v = $request->validate(['memberId' => ['required', 'integer', 'exists:members,id']]);
        $member = Member::withTrashed()->findOrFail($v['memberId']);

        return response()->json(['data' => $this->statementFor($member, ...$this->reportRange($request))]);
    }

    /** Statements for every member who gave anything in the year (for printing them all at once). */
    public function statements(Request $request): JsonResponse
    {
        [$from, $to, $year] = $this->reportRange($request);
        $range = [$from, $to];
        $ids = Offertory::whereDateBetween('date', $range)->whereNotNull('member_id')->distinct()->pluck('member_id')
            ->merge(Contribution::whereDateBetween('date', $range)->whereNotNull('member_id')->whereIn('kind', ['gift', 'in_kind'])->distinct()->pluck('member_id'))
            ->unique();

        $members = Member::withTrashed()->whereIn('id', $ids)->orderBy('pledge_number')->orderBy('name')->get();

        return response()->json(['data' => $members->map(fn ($m) => $this->statementFor($m, $from, $to, $year))->values()]);
    }

    /**
     * ?from=&to= when given, otherwise the whole of ?year= (default this year).
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function reportRange(Request $request): array
    {
        $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']]);
        [$from, $to] = $this->period($request);
        if ($from || $to) {
            $to ??= \App\Support\ChurchDate::today();
            $from ??= substr($to, 0, 4).'-01-01';

            return [$from, $to, (int) substr($from, 0, 4)];
        }
        $year = $request->integer('year', now()->year);

        return ["{$year}-01-01", "{$year}-12-31", $year];
    }

    private function statementFor(Member $member, string $from, string $to, int $year): array
    {
        $range = [$from, $to];
        $gifts = Offertory::with('pledge')->where('member_id', $member->id)->whereDateBetween('date', $range)->orderBy('date')->get();
        // project gifts not already counted as offertory (thanksgiving / pledge payments are in $gifts)
        $donations = Contribution::with('project')->where('member_id', $member->id)->whereIn('kind', ['gift', 'in_kind'])->whereDateBetween('date', $range)->orderBy('date')->get();
        $pledges = Pledge::withSum('payments', 'amount')->where('member_id', $member->id)
            ->where(fn ($q) => $q->whereDateBetween('pledge_date', $range)->orWhereHas('payments', fn ($q) => $q->whereDateBetween('date', $range)))
            ->get();

        $byCategory = collect(Offertory::CATEGORIES)->mapWithKeys(fn ($c) => [$c => round((float) $gifts->where('category', $c)->sum('amount'), 2)]);
        $offertoryTotal = round((float) $gifts->sum('amount'), 2);
        $donationTotal = round((float) $donations->sum('amount'), 2);
        $inKindTotal = round((float) $donations->sum('in_kind_value'), 2);

        return [
            'year' => $year, 'from' => $from, 'to' => $to,
            'member' => [
                'id' => (string) $member->id, 'name' => $member->name, 'pledgeNumber' => $member->pledge_number,
                'phone' => $member->phone, 'address' => collect([$member->village, $member->parish])->filter()->implode(', ') ?: null,
            ],
            'byCategory' => $byCategory,
            'offertoryTotal' => $offertoryTotal,
            'donationTotal' => $donationTotal,
            'grandTotal' => round($offertoryTotal + $donationTotal, 2),
            'gifts' => $gifts->map(fn (Offertory $o) => [
                'date' => $o->date->toDateString(), 'category' => $o->category, 'service' => $o->service,
                'amount' => (float) $o->amount, 'reference' => $o->reference, 'pledgePurpose' => $o->pledge?->purpose,
            ])->values(),
            'inKindTotal' => $inKindTotal,
            'donations' => $donations->map(fn (Contribution $d) => [
                'date' => $d->date->toDateString(), 'campaign' => $d->project?->name, 'amount' => (float) $d->amount,
                'inKind' => $d->kind === 'in_kind' ? $d->in_kind_description : null, 'inKindValue' => $d->in_kind_value !== null ? (float) $d->in_kind_value : null,
                'reference' => $d->reference,
            ])->values(),
            'pledges' => $pledges->map(fn (Pledge $p) => [
                'purpose' => $p->purpose, 'amount' => (float) $p->amount, 'paid' => round($p->paid(), 2),
                'balance' => round(max(0, (float) $p->amount - $p->paid()), 2), 'status' => $p->status(),
            ])->values(),
        ];
    }
}
