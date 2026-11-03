<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\WelfareCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Welfare / benevolence. The fund balance is the "Welfare" category in the Accounts ledger:
 * contributions are Welfare income, payouts are Welfare expenses (created when a case is paid).
 */
class WelfareController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = WelfareCase::query()->with(['member', 'decider', 'transaction'])
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where('beneficiary', 'like', "%{$s}%"))
            ->inPeriod('created_at', ...$this->period($request))
            ->latest()->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($c) => $this->present($c)), 'meta' => $this->meta($rows)]);
    }

    public function stats(): JsonResponse
    {
        $cat = WelfareCase::LEDGER_CATEGORY;
        $in = (float) Transaction::where('category', $cat)->where('type', 'income')->sum('amount');
        $out = (float) Transaction::where('category', $cat)->where('type', 'expense')->sum('amount');
        $year = now()->year;

        return response()->json(['data' => [
            'fundBalance' => round($in - $out, 2),
            'contributed' => round($in, 2),
            'paidThisYear' => round((float) Transaction::where('category', $cat)->where('type', 'expense')->whereYear('date', $year)->sum('amount'), 2),
            'pending' => WelfareCase::where('status', 'pending')->count(),
            'awaitingPayment' => WelfareCase::where('status', 'approved')->count(),
            'awaitingPaymentAmount' => round((float) WelfareCase::where('status', 'approved')->sum('amount_approved'), 2),
            'helpedThisYear' => WelfareCase::where('status', 'paid')->whereYear('decided_at', $year)->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = WelfareCase::create([...$this->validated($request), 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($c->load('member'))], 201);
    }

    public function update(Request $request, WelfareCase $welfare): JsonResponse
    {
        abort_if($welfare->status === 'paid', 422, 'A paid case can no longer be edited.');
        $welfare->update($this->validated($request, true));

        return response()->json(['data' => $this->present($welfare->fresh(['member', 'decider']))]);
    }

    public function destroy(WelfareCase $welfare): Response
    {
        abort_if($welfare->status === 'paid', 422, 'A paid case is part of the accounts and cannot be deleted.');
        $welfare->delete();

        return response()->noContent();
    }

    /** approve (with amount) or decline a pending case. */
    public function decide(Request $request, WelfareCase $welfare): JsonResponse
    {
        abort_unless(in_array($welfare->status, ['pending', 'approved', 'declined'], true), 422, 'This case has already been paid.');
        $v = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'decline'])],
            'amount' => ['required_if:decision,approve', 'nullable', 'numeric', 'gt:0', 'max:999999999999'],
        ]);

        $welfare->update([
            'status' => $v['decision'] === 'approve' ? 'approved' : 'declined',
            'amount_approved' => $v['decision'] === 'approve' ? $v['amount'] : null,
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        return response()->json(['data' => $this->present($welfare->fresh(['member', 'decider']))]);
    }

    /** Pay an approved case: posts a Welfare expense to the ledger and links it. */
    public function pay(Request $request, WelfareCase $welfare): JsonResponse
    {
        abort_unless($welfare->status === 'approved', 422, 'Only approved cases can be paid.');
        $v = $request->validate([
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'paymentMethod' => ['required', 'string', 'max:50'],
        ]);

        DB::transaction(function () use ($welfare, $v, $request) {
            $tx = Transaction::create([
                'date' => $v['date'], 'type' => 'expense', 'category' => WelfareCase::LEDGER_CATEGORY,
                'amount' => $welfare->amount_approved, 'payment_method' => $v['paymentMethod'],
                'description' => sprintf('Welfare (%s): %s', str_replace('_', ' ', $welfare->category), $welfare->beneficiary),
                'payee' => $welfare->beneficiary, 'recorded_by' => $request->user()->id,
            ]);
            $welfare->update(['status' => 'paid', 'transaction_id' => $tx->id]);
        });

        return response()->json(['data' => $this->present($welfare->fresh(['member', 'decider', 'transaction']))]);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        $v = $request->validate([
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'beneficiary' => [$update ? 'sometimes' : 'required_without:memberId', 'nullable', 'string', 'max:255'],
            'category' => [$req, Rule::in(WelfareCase::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'amountRequested' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
        ]);
        $data = collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
        if (array_key_exists('beneficiary', $data) && $data['beneficiary'] === null) {
            unset($data['beneficiary']);
        }
        if (! empty($data['member_id']) && empty($data['beneficiary'])) {
            $data['beneficiary'] = Member::find($data['member_id'])->name;
        }

        return $data;
    }

    private function present(WelfareCase $c): array
    {
        return [
            'id' => (string) $c->id,
            'memberId' => $c->member_id ? (string) $c->member_id : null,
            'pledgeNumber' => $c->member?->pledge_number,
            'beneficiary' => $c->beneficiary,
            'category' => $c->category,
            'description' => $c->description,
            'amountRequested' => $c->amount_requested !== null ? (float) $c->amount_requested : null,
            'amountApproved' => $c->amount_approved !== null ? (float) $c->amount_approved : null,
            'status' => $c->status,
            'decidedBy' => $c->decider?->name,
            'decidedAt' => $c->decided_at?->toDateString(),
            'paidReference' => $c->transaction?->reference,
            'createdAt' => $c->created_at->toDateString(),
        ];
    }
}
