<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Journal;
use App\Models\Project;
use App\Services\Accounting\Ledger;
use App\Support\ChurchDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Chart of accounts, mappings, manual journals and the financial statements. */
class AccountingController extends Controller
{
    // ---------------------------------------------------------------- chart of accounts

    public function accounts(): JsonResponse
    {
        $ledger = new Ledger;
        $mapped = AccountMapping::all()->groupBy('account_id');

        return response()->json(['data' => $ledger->accounts->values()->map(fn (Account $a) => $this->presentAccount($a) + [
            'mappedFrom' => ($mapped[$a->id] ?? collect())->map(fn ($m) => ['source' => $m->source, 'key' => $m->key])->values(),
        ])]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $a = Account::create($this->accountData($request));

        return response()->json(['data' => $this->presentAccount($a)], 201);
    }

    public function updateAccount(Request $request, Account $account): JsonResponse
    {
        $data = $this->accountData($request, $account);
        if ($account->is_system) {
            unset($data['type']); // system accounts keep their type
        }
        $account->update($data);

        return response()->json(['data' => $this->presentAccount($account)]);
    }

    public function destroyAccount(Account $account): Response
    {
        abort_if($account->is_system, 422, 'This account is needed by the ledger and can only be renamed.');
        abort_if((new Ledger)->hasPostings($account), 422, 'This account has postings or mappings. Deactivate it instead.');
        $account->delete();

        return response()->noContent();
    }

    private function accountData(Request $request, ?Account $existing = null): array
    {
        $v = $request->validate([
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:10', 'regex:/^[0-9A-Za-z.-]+$/', Rule::unique('accounts', 'code')->ignore($existing)],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:150'],
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(Account::TYPES)],
            'subtype' => ['nullable', Rule::in(Account::SUBTYPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return $v;
    }

    private function presentAccount(Account $a): array
    {
        return ['id' => (string) $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'subtype' => $a->subtype,
            'description' => $a->description, 'isSystem' => $a->is_system, 'active' => $a->active];
    }

    // ---------------------------------------------------------------- mappings

    public function mappings(): JsonResponse
    {
        $ledger = new Ledger;
        $rows = AccountMapping::with('account')->orderBy('source')->orderBy('key')->get();

        return response()->json(['data' => [
            'mappings' => $rows->map(fn ($m) => ['source' => $m->source, 'key' => $m->key, 'accountId' => (string) $m->account_id,
                'account' => "{$m->account->code} {$m->account->name}"])->values(),
            'unmapped' => $ledger->unmapped(),
        ]]);
    }

    public function updateMappings(Request $request): JsonResponse
    {
        $v = $request->validate([
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.source' => ['required', Rule::in(AccountMapping::SOURCES)],
            'mappings.*.key' => ['required', 'string', 'max:100'],
            'mappings.*.accountId' => ['required', 'integer', 'exists:accounts,id'],
        ]);
        $types = Account::pluck('type', 'id');
        foreach ($v['mappings'] as $i => $m) {
            $allowed = match ($m['source']) {
                'payment_method' => ['asset'],
                'offertory', 'income' => ['income'],
                'expense' => ['expense', 'asset'], // an asset = capital spending, e.g. buying land
            };
            if (! in_array($types[$m['accountId']] ?? null, $allowed, true)) {
                throw ValidationException::withMessages(["mappings.{$i}.accountId" => 'That account type cannot receive '.str_replace('_', ' ', $m['source']).' postings.']);
            }
        }
        DB::transaction(function () use ($v) {
            foreach ($v['mappings'] as $m) {
                AccountMapping::updateOrCreate(['source' => $m['source'], 'key' => $m['key']], ['account_id' => $m['accountId']]);
            }
        });

        return $this->mappings();
    }

    // ---------------------------------------------------------------- journals

    public function journals(Request $request): JsonResponse
    {
        $rows = Journal::with(['lines.account', 'lines.project', 'recorder'])
            ->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('memo', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")))
            ->inPeriod('date', ...$this->period($request))
            ->latest('date')->latest('id')->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($j) => $this->presentJournal($j)), 'meta' => $this->meta($rows)]);
    }

    public function storeJournal(Request $request): JsonResponse
    {
        $v = $this->journalData($request);
        $j = DB::transaction(function () use ($v, $request) {
            $j = Journal::create(['date' => $v['date'], 'memo' => $v['memo'], 'kind' => $v['kind'] ?? 'general',
                'reference' => $v['reference'] ?? null, 'recorded_by' => $request->user()->id]);
            $this->writeLines($j, $v['lines']);

            return $j;
        });

        return response()->json(['data' => $this->presentJournal($j->load(['lines.account', 'lines.project', 'recorder']))], 201);
    }

    public function updateJournal(Request $request, Journal $journal): JsonResponse
    {
        $v = $this->journalData($request, $journal);
        DB::transaction(function () use ($v, $journal) {
            $journal->update(['date' => $v['date'], 'memo' => $v['memo'], 'kind' => $v['kind'] ?? $journal->kind]);
            $journal->lines()->delete();
            $this->writeLines($journal, $v['lines']);
        });

        return response()->json(['data' => $this->presentJournal($journal->fresh(['lines.account', 'lines.project', 'recorder']))]);
    }

    public function destroyJournal(Journal $journal): Response
    {
        $journal->delete();

        return response()->noContent();
    }

    private function journalData(Request $request, ?Journal $existing = null): array
    {
        $v = $request->validate([
            'date' => ['required', 'date', ChurchDate::notFuture()],
            'memo' => ['required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(Journal::KINDS)],
            'reference' => ['nullable', 'string', 'max:50', Rule::unique('journals', 'reference')->ignore($existing)],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.accountId' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.fund' => ['nullable', Rule::in(['general', 'welfare', 'project'])],
            'lines.*.projectId' => ['nullable', 'required_if:lines.*.fund,project', 'integer', 'exists:projects,id'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);
        $dr = 0;
        $cr = 0;
        foreach ($v['lines'] as $i => $l) {
            $d = (float) ($l['debit'] ?? 0);
            $c = (float) ($l['credit'] ?? 0);
            if (($d > 0) === ($c > 0)) {
                throw ValidationException::withMessages(["lines.{$i}.debit" => 'Each line needs either a debit or a credit.']);
            }
            $dr += $d;
            $cr += $c;
        }
        if (abs($dr - $cr) >= 0.005) {
            throw ValidationException::withMessages(['lines' => sprintf('Debits (%s) and credits (%s) must be equal.', number_format($dr, 2), number_format($cr, 2))]);
        }

        return $v;
    }

    private function writeLines(Journal $j, array $lines): void
    {
        foreach ($lines as $l) {
            $fund = $l['fund'] ?? 'general';
            $j->lines()->create(['account_id' => $l['accountId'], 'fund' => $fund, 'project_id' => $fund === 'project' ? $l['projectId'] : null,
                'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0, 'memo' => $l['memo'] ?? null]);
        }
    }

    private function presentJournal(Journal $j): array
    {
        return [
            'id' => (string) $j->id, 'date' => $j->date->toDateString(), 'reference' => $j->reference, 'memo' => $j->memo, 'kind' => $j->kind,
            'recordedBy' => $j->recorder?->name,
            'total' => round((float) $j->lines->sum('debit'), 2),
            'lines' => $j->lines->map(fn ($l) => [
                'accountId' => (string) $l->account_id, 'account' => "{$l->account->code} {$l->account->name}",
                'fund' => $l->fund, 'projectId' => $l->project_id ? (string) $l->project_id : null, 'project' => $l->project?->name,
                'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'memo' => $l->memo,
            ])->values(),
        ];
    }

    // ---------------------------------------------------------------- statements

    private function dates(Request $request, bool $range): array
    {
        $today = ChurchDate::today();
        $v = $request->validate([
            'asOf' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        if (! $range) {
            return [substr($v['asOf'] ?? $today, 0, 10)];
        }
        $to = substr($v['to'] ?? $today, 0, 10);

        return [substr($v['from'] ?? (new Ledger)->yearStart($to), 0, 10), $to];
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$asOf] = $this->dates($request, false);

        return response()->json(['data' => (new Ledger)->trialBalance($asOf)]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        [$from, $to] = $this->dates($request, true);

        return response()->json(['data' => (new Ledger)->incomeStatement($from, $to)]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        [$asOf] = $this->dates($request, false);

        return response()->json(['data' => (new Ledger)->balanceSheet($asOf)]);
    }

    public function generalLedger(Request $request, Account $account): JsonResponse
    {
        [$from, $to] = $this->dates($request, true);

        return response()->json(['data' => (new Ledger)->generalLedger($account, $from, $to)]);
    }

    /** Fund choices for journal lines. */
    public function funds(): JsonResponse
    {
        return response()->json(['data' => collect([['key' => 'general', 'name' => 'General fund'], ['key' => 'welfare', 'name' => 'Welfare fund']])
            ->merge(Project::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['key' => 'project', 'projectId' => (string) $p->id, 'name' => "{$p->name} fund"]))]);
    }
}
