<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Offertory;
use App\Models\Pledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Promised giving (building fund, harvest…) and the payments made against each pledge. */
class PledgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Pledge::query()->with(['member', 'project'])->withSum('payments', 'amount')
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->when($request->query('projectId'), fn ($q, $v) => $q->where('project_id', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('pledger_name', 'like', "%{$s}%")->orWhere('purpose', 'like', "%{$s}%")
                ->orWhereHas('member', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('pledge_number', 'like', "%{$s}%"))))
            ->inPeriod('pledge_date', ...$this->period($request))
            ->latest('pledge_date')->latest('id')
            ->get()
            ->filter(fn (Pledge $p) => ! $request->query('status') || $p->status() === $request->query('status'))
            ->values();

        $page = max(1, $request->integer('page', 1));
        $per = $this->perPage($request);
        $slice = $rows->forPage($page, $per)->values();

        return response()->json([
            'data' => $slice->map(fn ($p) => $this->present($p)),
            'meta' => [
                'current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $per)), 'per_page' => $per,
                'total' => $rows->count(), 'from' => $slice->isEmpty() ? null : ($page - 1) * $per + 1,
                'to' => $slice->isEmpty() ? null : ($page - 1) * $per + $slice->count(),
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $all = Pledge::withSum('payments', 'amount')->get();
        $promised = (float) $all->sum('amount');
        $paid = (float) $all->sum(fn ($p) => $p->paid());

        return response()->json(['data' => [
            'promised' => round($promised, 2),
            'paid' => round($paid, 2),
            'outstanding' => round(max(0, $promised - $paid), 2),
            'pledges' => $all->count(),
            'fulfilled' => $all->filter(fn ($p) => $p->status() === 'fulfilled')->count(),
            'overdue' => $all->filter(fn ($p) => $p->status() === 'overdue')->count(),
            'byPurpose' => $all->groupBy('purpose')->map(fn ($g, $purpose) => [
                'purpose' => $purpose,
                'promised' => round((float) $g->sum('amount'), 2),
                'paid' => round((float) $g->sum(fn ($p) => $p->paid()), 2),
            ])->values(),
        ]]);
    }

    public function show(Pledge $pledge): JsonResponse
    {
        $pledge->load(['member', 'project', 'payments' => fn ($q) => $q->latest('date')]);

        return response()->json(['data' => $this->present($pledge) + [
            'payments' => $pledge->payments->map(fn (Offertory $o) => [
                'id' => (string) $o->id, 'date' => $o->date->toDateString(), 'amount' => (float) $o->amount,
                'paymentMethod' => $o->payment_method, 'reference' => $o->reference, 'service' => $o->service,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = Pledge::create([...$this->validated($request), 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($p->load(['member', 'project']))], 201);
    }

    public function update(Request $request, Pledge $pledge): JsonResponse
    {
        $pledge->update($this->validated($request, true));

        return response()->json(['data' => $this->present($pledge->fresh(['member', 'project']))]);
    }

    public function destroy(Pledge $pledge): Response
    {
        $pledge->delete(); // payments stay as ordinary pledge offerings (pledge_id set null)

        return response()->noContent();
    }

    /** Record a payment: creates an offertory record (category "pledge") linked to this pledge. */
    public function pay(Request $request, Pledge $pledge): JsonResponse
    {
        $v = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'paymentMethod' => ['required', 'string', 'max:50'],
            'service' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:50', 'unique:offertories,reference'],
        ]);

        if ($pledge->project_id) {
            // Project pledges are paid through contributions, so the project total and the offertory both see it.
            app(\App\Services\ContributionService::class)->record($pledge->project, [
                'kind' => 'pledge_payment', 'pledge_id' => $pledge->id, 'date' => $v['date'], 'amount' => $v['amount'],
                'payment_method' => $v['paymentMethod'], 'service' => $v['service'] ?? null, 'reference' => $v['reference'] ?? null,
            ], $request->user(), $request->boolean('sendThanks'));

            return $this->show($pledge->fresh())->setStatusCode(201);
        }

        Offertory::create([
            'date' => $v['date'], 'service' => $v['service'] ?? null, 'category' => 'pledge', 'amount' => $v['amount'],
            'payment_method' => $v['paymentMethod'], 'reference' => $v['reference'] ?? null,
            'member_id' => $pledge->member_id, 'contributor_name' => $pledge->member_id ? null : $pledge->pledger_name,
            'pledge_id' => $pledge->id, 'notes' => "Payment towards: {$pledge->purpose}", 'recorded_by' => $request->user()->id,
        ]);

        return $this->show($pledge->fresh())->setStatusCode(201);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        $v = $request->validate([
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'pledgerName' => [$update ? 'sometimes' : 'required_without:memberId', 'nullable', 'string', 'max:255'],
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'purpose' => [$req, 'string', 'max:255'],
            'amount' => [$req, 'numeric', 'gt:0', 'max:999999999999'],
            'pledgeDate' => [$req, 'date'],
            'dueDate' => ['nullable', 'date', Rule::when($request->filled('pledgeDate'), 'after_or_equal:pledgeDate')],
            'notes' => ['nullable', 'string'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [\Illuminate\Support\Str::snake($k) => $val])->all();
    }

    private function present(Pledge $p): array
    {
        $paid = $p->paid();

        return [
            'id' => (string) $p->id,
            'memberId' => $p->member_id ? (string) $p->member_id : null,
            'pledgeNumber' => $p->member?->pledge_number,
            'pledger' => $p->pledgerLabel(),
            'projectId' => $p->project_id ? (string) $p->project_id : null,
            'project' => $p->project?->name,
            'purpose' => $p->purpose,
            'amount' => (float) $p->amount,
            'paid' => round($paid, 2),
            'balance' => round(max(0, (float) $p->amount - $paid), 2),
            'pledgeDate' => $p->pledge_date->toDateString(),
            'dueDate' => $p->due_date?->toDateString(),
            'status' => $p->status(),
            'notes' => $p->notes,
        ];
    }
}
