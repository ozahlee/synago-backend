<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Project;
use App\Models\Setting;
use App\Models\WelfareCase;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The general ledger, read straight from the books staff already keep:
 *
 *   offertory            Dr cash/bank/mobile money (payment method)   Cr income (offertory category)
 *   income transaction   Dr cash/bank/mobile money                    Cr income (category)
 *   expense transaction  Dr expense — or an asset, e.g. land (cat.)   Cr cash/bank/mobile money
 *   manual journal       its own lines (opening balances, banking, assets, loans, depreciation…)
 *
 * Every posting carries a fund: money tied to a project is that project's restricted fund, welfare
 * money is the welfare fund, the rest is the general fund. Because the postings are derived when a
 * report runs, editing an offertory or a mapping is reflected everywhere at once and the books
 * always balance. Gifts in kind stay off the books (memo only, on the project page).
 */
class Ledger
{
    /** @var array<string, array<string, int>> source => key => account id */
    private array $map = [];

    /** @var array<string, int> */
    private array $fallback = [];

    /** @var Collection<int, Account> */
    public Collection $accounts;

    public function __construct()
    {
        $this->accounts = Account::orderBy('code')->get()->keyBy('id');
        $byCode = $this->accounts->keyBy('code');
        foreach (AccountMapping::all() as $m) {
            $this->map[$m->source][$m->key] = $m->account_id;
        }
        foreach (ChartOfAccounts::FALLBACK as $source => $code) {
            $this->fallback[$source] = $byCode[$code]->id ?? $this->accounts->first()?->id;
        }
    }

    public function accountFor(string $source, ?string $key): int
    {
        return $this->map[$source][$key ?? ''] ?? $this->fallback[$source];
    }

    /** Category / payment-method values in use that have no mapping (they post to the fallback account). */
    public function unmapped(): array
    {
        $used = [
            'payment_method' => self::own('offertories')->distinct()->pluck('payment_method')
                ->merge(self::own('transactions')->whereNotNull('payment_method')->distinct()->pluck('payment_method')),
            'offertory' => self::own('offertories')->distinct()->pluck('category'),
            'income' => self::own('transactions')->where('type', 'income')->distinct()->pluck('category'),
            'expense' => self::own('transactions')->where('type', 'expense')->distinct()->pluck('category'),
        ];

        return collect($used)->map(fn ($keys, $source) => $keys->filter()->unique()
            ->reject(fn ($k) => isset($this->map[$source][$k]))->values()->all())->filter()->all();
    }

    // ------------------------------------------------------------------ church

    /** A raw query on one of the current church's tables (raw queries skip the model's church filter). */
    private static function own(string $table, ?string $alias = null): Builder
    {
        return self::church(DB::table($alias ? "{$table} as {$alias}" : $table), ($alias ?? $table).'.church_id');
    }

    private static function church(Builder $q, string $column): Builder
    {
        $id = Tenant::id();
        abort_unless($id, 500, 'The ledger needs a church.');

        return $q->where($column, $id);
    }

    // ------------------------------------------------------------------ dates

    public function yearStartMonth(): int
    {
        return max(1, min(12, (int) Setting::get('financialYearStartMonth', 1)));
    }

    /** First day of the financial year containing $date. */
    public function yearStart(string $date): string
    {
        $d = Carbon::parse($date);
        $m = $this->yearStartMonth();
        $year = $d->month >= $m ? $d->year : $d->year - 1;

        return Carbon::create($year, $m, 1)->toDateString();
    }

    private static function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }

    /** Dates are stored either as "Y-m-d" or "Y-m-d 00:00:00"; compare as strings against day bounds. */
    private static function window(Builder $q, string $col, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $q->where($col, '>=', $from);
        }
        if ($to) {
            $q->where($col, '<', Carbon::parse($to)->addDay()->toDateString());
        }

        return $q;
    }

    // ------------------------------------------------------------------ funds

    public static function fundKey(?int $projectId, ?string $category = null, ?string $explicit = null): string
    {
        if ($explicit !== null && $explicit !== 'project') {
            return $explicit;
        }
        if ($projectId) {
            return "p:{$projectId}";
        }

        return $category === WelfareCase::LEDGER_CATEGORY ? 'welfare' : 'general';
    }

    public static function isRestricted(string $fund): bool
    {
        return $fund !== 'general';
    }

    /** @param array<int, string> $keys */
    public function fundNames(array $keys): array
    {
        $ids = collect($keys)->filter(fn ($k) => str_starts_with($k, 'p:'))->map(fn ($k) => (int) substr($k, 2))->all();
        $projects = Project::whereIn('id', $ids)->pluck('name', 'id');

        return collect($keys)->mapWithKeys(fn ($k) => [$k => match (true) {
            $k === 'general' => 'General fund',
            $k === 'welfare' => 'Welfare fund',
            default => ($projects[(int) substr($k, 2)] ?? 'Project').' fund',
        }])->all();
    }

    // ------------------------------------------------------------------ balances

    /**
     * Debit and credit totals per account and fund for a date window (either end open).
     *
     * @return array<int, array<string, array{debit: float, credit: float}>>
     */
    public function balances(?string $from, ?string $to): array
    {
        $out = [];
        $add = function (int $account, string $fund, float $dr, float $cr) use (&$out) {
            $out[$account][$fund]['debit'] = ($out[$account][$fund]['debit'] ?? 0) + $dr;
            $out[$account][$fund]['credit'] = ($out[$account][$fund]['credit'] ?? 0) + $cr;
        };

        $offertory = self::window(self::own('offertories', 'o'), 'o.date', $from, $to)
            ->leftJoin('pledges as p', 'p.id', '=', 'o.pledge_id')
            ->leftJoin('contributions as c', 'c.offertory_id', '=', 'o.id')
            ->selectRaw('o.category, o.payment_method, coalesce(p.project_id, c.project_id) as project_id, sum(o.amount) as total')
            ->groupBy('o.category', 'o.payment_method')->groupByRaw('coalesce(p.project_id, c.project_id)')->get();
        foreach ($offertory as $r) {
            $fund = self::fundKey($r->project_id ? (int) $r->project_id : null);
            $add($this->accountFor('payment_method', $r->payment_method), $fund, (float) $r->total, 0);
            $add($this->accountFor('offertory', $r->category), $fund, 0, (float) $r->total);
        }

        $tx = self::window(self::own('transactions'), 'date', $from, $to)
            ->selectRaw('type, category, payment_method, project_id, sum(amount) as total')
            ->groupBy('type', 'category', 'payment_method', 'project_id')->get();
        foreach ($tx as $r) {
            $fund = self::fundKey($r->project_id ? (int) $r->project_id : null, $r->category);
            $cash = $this->accountFor('payment_method', $r->payment_method);
            if ($r->type === 'income') {
                $add($cash, $fund, (float) $r->total, 0);
                $add($this->accountFor('income', $r->category), $fund, 0, (float) $r->total);
            } else {
                $add($this->accountFor('expense', $r->category), $fund, (float) $r->total, 0);
                $add($cash, $fund, 0, (float) $r->total);
            }
        }

        $lines = self::window(self::church(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id'), 'j.church_id'), 'j.date', $from, $to)
            ->selectRaw('l.account_id, l.fund, l.project_id, sum(l.debit) as dr, sum(l.credit) as cr')
            ->groupBy('l.account_id', 'l.fund', 'l.project_id')->get();
        foreach ($lines as $r) {
            $add((int) $r->account_id, self::fundKey($r->project_id ? (int) $r->project_id : null, null, $r->fund), (float) $r->dr, (float) $r->cr);
        }

        return $out;
    }

    /** Net (debit − credit) per account, all funds together. */
    private static function net(array $balances, int $account, ?callable $fundFilter = null): float
    {
        return collect($balances[$account] ?? [])
            ->filter(fn ($v, $fund) => ! $fundFilter || $fundFilter($fund))
            ->sum(fn ($v) => $v['debit'] - $v['credit']);
    }

    /** Surplus (income − expenses) per fund in a balance set. */
    private function surplusByFund(array $balances): array
    {
        $out = [];
        foreach ($balances as $account => $funds) {
            if (! in_array($this->accounts[$account]->type ?? null, ['income', 'expense'], true)) {
                continue;
            }
            foreach ($funds as $fund => $v) {
                $out[$fund] = ($out[$fund] ?? 0) + $v['credit'] - $v['debit'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ reports

    public function trialBalance(string $asOf): array
    {
        $start = $this->yearStart($asOf);
        $bs = $this->balances(null, $asOf);
        $pl = $this->balances($start, $asOf);
        $prior = $this->surplusByFund($this->balances(null, self::dayBefore($start)));
        $general = $this->accounts->firstWhere('code', '3000');
        $restricted = $this->accounts->firstWhere('code', '3100');

        $rows = $this->accounts->map(function (Account $a) use ($bs, $pl, $prior, $general, $restricted) {
            $net = in_array($a->type, ['income', 'expense'], true) ? self::net($pl, $a->id) : self::net($bs, $a->id);
            // earlier years' surplus is carried in the fund accounts
            if ($general && $a->id === $general->id) {
                $net -= $prior['general'] ?? 0;
            }
            if ($restricted && $a->id === $restricted->id) {
                $net -= collect($prior)->except('general')->sum();
            }
            $net = round($net, 2);

            return ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type,
                'debit' => $net > 0 ? $net : 0.0, 'credit' => $net < 0 ? -$net : 0.0];
        })->filter(fn ($r) => $r['debit'] != 0 || $r['credit'] != 0)->values();

        $dr = round($rows->sum('debit'), 2);
        $cr = round($rows->sum('credit'), 2);

        return ['asOf' => $asOf, 'yearStart' => $start, 'rows' => $rows, 'totals' => ['debit' => $dr, 'credit' => $cr],
            'balanced' => abs($dr - $cr) < 0.01, 'unmapped' => $this->unmapped()];
    }

    public function incomeStatement(string $from, string $to): array
    {
        $cur = $this->balances($from, $to);
        // same period one year earlier
        $pFrom = Carbon::parse($from)->subYear()->toDateString();
        $pTo = Carbon::parse($to)->subYear()->toDateString();
        $prev = $this->balances($pFrom, $pTo);
        $budget = $this->budgetByAccount($from, $to);

        $section = function (string $type) use ($cur, $prev, $budget) {
            $sign = $type === 'income' ? -1 : 1; // show both as positive amounts
            $rows = $this->accounts->where('type', $type)->map(function (Account $a) use ($cur, $prev, $budget, $sign) {
                $general = round($sign * self::net($cur, $a->id, fn ($f) => $f === 'general'), 2);
                $restricted = round($sign * self::net($cur, $a->id, fn ($f) => $f !== 'general'), 2);

                return ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name,
                    'general' => $general, 'restricted' => $restricted, 'total' => round($general + $restricted, 2),
                    'previous' => round($sign * self::net($prev, $a->id), 2),
                    'budget' => $budget[$a->id] ?? null];
            })->filter(fn ($r) => $r['total'] != 0 || $r['previous'] != 0 || $r['budget'])->values();

            return ['rows' => $rows, 'total' => [
                'general' => round($rows->sum('general'), 2), 'restricted' => round($rows->sum('restricted'), 2),
                'total' => round($rows->sum('total'), 2), 'previous' => round($rows->sum('previous'), 2),
                'budget' => $rows->whereNotNull('budget')->isEmpty() ? null : round($rows->sum('budget'), 2),
            ]];
        };

        $income = $section('income');
        $expenses = $section('expense');
        $surplus = collect(['general', 'restricted', 'total', 'previous'])
            ->mapWithKeys(fn ($k) => [$k => round($income['total'][$k] - $expenses['total'][$k], 2)])->all();

        return ['from' => $from, 'to' => $to, 'previousFrom' => $pFrom, 'previousTo' => $pTo,
            'income' => $income, 'expenses' => $expenses, 'surplus' => $surplus, 'unmapped' => $this->unmapped()];
    }

    /** Monthly budgets (YYYY-MM periods) that fall inside the window, per expense account. */
    private function budgetByAccount(string $from, string $to): array
    {
        $out = [];
        $rows = self::own('budgets')->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])
            ->selectRaw('category, sum(allocated) as total')->groupBy('category')->get();
        foreach ($rows as $r) {
            $acc = $this->accountFor('expense', $r->category);
            $out[$acc] = round(($out[$acc] ?? 0) + (float) $r->total, 2);
        }

        return $out;
    }

    public function balanceSheet(string $asOf): array
    {
        $start = $this->yearStart($asOf);
        $all = $this->balances(null, $asOf);
        $before = $this->balances(null, self::dayBefore($start));

        $line = fn (Account $a, int $sign) => ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'subtype' => $a->subtype,
            'amount' => round($sign * self::net($all, $a->id), 2)];

        $assets = $this->accounts->where('type', 'asset')->map(fn ($a) => $line($a, 1))->filter(fn ($r) => $r['amount'] != 0)->values();
        $current = $assets->filter(fn ($r) => ! in_array($r['subtype'], ['fixed_asset', 'contra_asset'], true))->values();
        $fixed = $assets->filter(fn ($r) => in_array($r['subtype'], ['fixed_asset', 'contra_asset'], true))->values();
        $liabilities = $this->accounts->where('type', 'liability')->map(fn ($a) => $line($a, -1))->filter(fn ($r) => $r['amount'] != 0)->values();

        // fund balances = everything credited to the fund (income, opening balances) less what it spent
        $fundTotals = function (array $balances) {
            $out = [];
            foreach ($balances as $account => $funds) {
                if (! in_array($this->accounts[$account]->type ?? null, ['income', 'expense', 'fund'], true)) {
                    continue;
                }
                foreach ($funds as $fund => $v) {
                    $out[$fund] = ($out[$fund] ?? 0) + $v['credit'] - $v['debit'];
                }
            }

            return $out;
        };
        $closing = $fundTotals($all);
        $opening = $fundTotals($before);
        // opening-balance journals dated inside the year are part of where the funds started, not this year's surplus
        $openingJournals = self::window(self::church(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id'), 'j.church_id'), 'j.date', $start, $asOf)
            ->join('accounts as a', 'a.id', '=', 'l.account_id')->where('j.kind', 'opening')->whereIn('a.type', ['income', 'expense', 'fund'])
            ->selectRaw('l.fund, l.project_id, sum(l.credit) - sum(l.debit) as net')->groupBy('l.fund', 'l.project_id')->get();
        foreach ($openingJournals as $r) {
            $k = self::fundKey($r->project_id ? (int) $r->project_id : null, null, $r->fund);
            $opening[$k] = ($opening[$k] ?? 0) + (float) $r->net;
        }
        $keys = collect(array_keys($closing + $opening))->sortBy(fn ($k) => $k === 'general' ? '0' : ($k === 'welfare' ? '1' : '2'.$k))->values()->all();
        $names = $this->fundNames($keys);
        $funds = collect($keys)->map(fn ($k) => [
            'key' => $k, 'name' => $names[$k], 'restricted' => self::isRestricted($k),
            'opening' => round($opening[$k] ?? 0, 2), 'movement' => round(($closing[$k] ?? 0) - ($opening[$k] ?? 0), 2), 'amount' => round($closing[$k] ?? 0, 2),
        ])->filter(fn ($f) => $f['amount'] != 0 || $f['opening'] != 0 || $f['key'] === 'general')->values();

        $totalAssets = round($assets->sum('amount'), 2);
        $totalLiabilities = round($liabilities->sum('amount'), 2);
        $totalFunds = round($funds->sum('amount'), 2);

        // things a treasurer should look at before presenting the statement
        $warnings = [];
        foreach ($current as $a) {
            if ($a['amount'] < 0 && in_array($a['subtype'], ['cash', 'mobile_money', 'bank'], true)) {
                $warnings[] = "{$a['name']} is negative (".number_format($a['amount']).'): more was paid out than recorded coming in. Record the opening balance or the banking/transfer journal.';
            }
        }
        foreach ($funds->where('restricted', true) as $f) {
            if ($f['amount'] < 0) {
                $warnings[] = "{$f['name']} is overspent by ".number_format(-$f['amount']).': the general fund is covering it.';
            }
        }

        return [
            'asOf' => $asOf, 'yearStart' => $start, 'warnings' => $warnings,
            'assets' => ['current' => $current, 'fixed' => $fixed, 'currentTotal' => round($current->sum('amount'), 2),
                'fixedTotal' => round($fixed->sum('amount'), 2), 'total' => $totalAssets],
            'liabilities' => ['rows' => $liabilities, 'total' => $totalLiabilities],
            'netAssets' => round($totalAssets - $totalLiabilities, 2),
            'funds' => ['rows' => $funds, 'general' => round($funds->where('restricted', false)->sum('amount'), 2),
                'restricted' => round($funds->where('restricted', true)->sum('amount'), 2), 'total' => $totalFunds],
            'balanced' => abs(($totalAssets - $totalLiabilities) - $totalFunds) < 0.01,
            'unmapped' => $this->unmapped(),
        ];
    }

    // ------------------------------------------------------------------ general ledger

    /** Restrict $col to the keys that post to $account for a mapping source (including fallback keys). */
    private function keysFor(Builder $q, string $col, string $source, int $account): bool
    {
        $mine = collect($this->map[$source] ?? [])->filter(fn ($id) => $id === $account)->keys()->all();
        $isFallback = ($this->fallback[$source] ?? null) === $account;
        if (! $mine && ! $isFallback) {
            return false;
        }
        $others = collect($this->map[$source] ?? [])->reject(fn ($id) => $id === $account)->keys()->all();
        $q->where(function ($w) use ($col, $mine, $isFallback, $others) {
            if ($mine) {
                $w->whereIn($col, $mine);
            }
            if ($isFallback) {
                $w->orWhere(fn ($x) => $x->whereNotIn($col, $others ?: ['__none__'])->orWhereNull($col));
            }
        });

        return true;
    }

    public function generalLedger(Account $account, string $from, string $to, int $limit = 3000): array
    {
        $isPl = in_array($account->type, ['income', 'expense'], true);
        $openFrom = $isPl ? $this->yearStart($from) : null;
        $opening = $from > ($openFrom ?? '0000') ? self::net($this->balances($openFrom, self::dayBefore($from)), $account->id) : 0.0;
        $projects = Project::pluck('name', 'id');
        $fundName = fn (string $k) => $k === 'general' ? 'General' : ($k === 'welfare' ? 'Welfare' : ($projects[(int) substr($k, 2)] ?? 'Project'));
        $rows = collect();

        $offertories = fn () => self::window(self::own('offertories', 'o'), 'o.date', $from, $to)
            ->leftJoin('members as m', 'm.id', '=', 'o.member_id')
            ->leftJoin('pledges as p', 'p.id', '=', 'o.pledge_id')
            ->leftJoin('contributions as c', 'c.offertory_id', '=', 'o.id')
            ->select('o.id', 'o.date', 'o.reference', 'o.category', 'o.service', 'o.amount', 'm.name as member', 'o.contributor_name')
            ->selectRaw('coalesce(p.project_id, c.project_id) as project_id');
        $describeOffertory = fn ($r) => collect([ucfirst($r->category), $r->service, $r->member ?? $r->contributor_name])->filter()->implode(' · ');

        foreach (['payment_method' => 'debit', 'offertory' => 'credit'] as $source => $side) {
            $q = $offertories();
            if ($this->keysFor($q, $source === 'payment_method' ? 'o.payment_method' : 'o.category', $source, $account->id)) {
                foreach ($q->get() as $r) {
                    $rows->push(['date' => substr($r->date, 0, 10), 'reference' => $r->reference, 'source' => 'offertory', 'sourceId' => (string) $r->id,
                        'description' => $describeOffertory($r), 'fund' => $fundName(self::fundKey($r->project_id ? (int) $r->project_id : null)),
                        'debit' => $side === 'debit' ? (float) $r->amount : 0.0, 'credit' => $side === 'credit' ? (float) $r->amount : 0.0]);
                }
            }
        }

        $txSides = [
            ['income', 'payment_method', 'payment_method', 'debit'], ['income', 'income', 'category', 'credit'],
            ['expense', 'expense', 'category', 'debit'], ['expense', 'payment_method', 'payment_method', 'credit'],
        ];
        foreach ($txSides as [$type, $source, $col, $side]) {
            $q = self::window(self::own('transactions'), 'date', $from, $to)->where('type', $type);
            if ($this->keysFor($q, $col, $source, $account->id)) {
                foreach ($q->get() as $r) {
                    $rows->push(['date' => substr($r->date, 0, 10), 'reference' => $r->reference, 'source' => 'transaction', 'sourceId' => (string) $r->id,
                        'description' => collect([$r->category, $r->description, $r->payee])->filter()->unique()->implode(' · '),
                        'fund' => $fundName(self::fundKey($r->project_id ? (int) $r->project_id : null, $r->category)),
                        'debit' => $side === 'debit' ? (float) $r->amount : 0.0, 'credit' => $side === 'credit' ? (float) $r->amount : 0.0]);
                }
            }
        }

        $jl = self::window(self::church(DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id'), 'j.church_id'), 'j.date', $from, $to)
            ->where('l.account_id', $account->id)
            ->select('j.id', 'j.date', 'j.reference', 'j.memo', 'l.memo as line_memo', 'l.fund', 'l.project_id', 'l.debit', 'l.credit')->get();
        foreach ($jl as $r) {
            $rows->push(['date' => substr($r->date, 0, 10), 'reference' => $r->reference, 'source' => 'journal', 'sourceId' => (string) $r->id,
                'description' => $r->line_memo ?: $r->memo, 'fund' => $fundName(self::fundKey($r->project_id ? (int) $r->project_id : null, null, $r->fund)),
                'debit' => (float) $r->debit, 'credit' => (float) $r->credit]);
        }

        $rows = $rows->sortBy([['date', 'asc'], ['reference', 'asc']])->values();
        $truncated = $rows->count() > $limit;
        $sign = $account->isDebitNormal() ? 1 : -1;
        $running = $sign * $opening;
        $rows = $rows->take($limit)->map(function ($r) use (&$running, $sign) {
            $running += $sign * ($r['debit'] - $r['credit']);

            return $r + ['balance' => round($running, 2)];
        });

        return [
            'account' => ['id' => (string) $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'debitNormal' => $account->isDebitNormal()],
            'from' => $from, 'to' => $to, 'opening' => round($sign * $opening, 2), 'rows' => $rows->values(),
            'totals' => ['debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2)],
            'closing' => round($running, 2), 'truncated' => $truncated,
        ];
    }

    /** Has anything ever posted to this account (so it may not be deleted)? */
    public function hasPostings(Account $account): bool
    {
        if ($account->lines()->exists() || $account->mappings()->exists()) {
            return true;
        }

        return in_array($account->id, $this->fallback, true);
    }
}
