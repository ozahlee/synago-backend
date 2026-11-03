<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = $this->filtered($request)
            ->latest('date')->latest('id')
            ->paginate($this->perPage($request));

        return TransactionResource::collection($records);
    }

    public function summary(Request $request): JsonResponse
    {
        $income = round((float) $this->filtered($request)->where('type', 'income')->sum('amount'), 2);
        $expense = round((float) $this->filtered($request)->where('type', 'expense')->sum('amount'), 2);

        return response()->json(['data' => [
            'income' => $income,
            'expense' => $expense,
            'balance' => round($income - $expense, 2),
        ]]);
    }

    /** Income vs expense per month for the last N months (default 6, max 24). */
    public function trends(Request $request): JsonResponse
    {
        $months = max(1, min(24, $request->integer('months', 6)));
        $from = now()->startOfMonth()->subMonths($months - 1);

        $grouped = Transaction::whereDate('date', '>=', $from)
            ->get(['date', 'type', 'amount'])
            ->groupBy(fn ($t) => $t->date->format('Y-m'));

        $data = collect(range(0, $months - 1))->map(function ($i) use ($from, $grouped) {
            $month = $from->copy()->addMonths($i);
            $rows = $grouped->get($month->format('Y-m'), collect());

            return [
                'month' => $month->format('M'),
                'period' => $month->format('Y-m'),
                'income' => round((float) $rows->where('type', 'income')->sum('amount'), 2),
                'expenses' => round((float) $rows->where('type', 'expense')->sum('amount'), 2),
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(TransactionRequest $request): TransactionResource
    {
        return new TransactionResource(
            Transaction::create([...$request->payload(), 'recorded_by' => $request->user()->id])
        );
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($transaction);
    }

    public function update(TransactionRequest $request, Transaction $transaction): TransactionResource
    {
        $transaction->update($request->payload());

        return new TransactionResource($transaction);
    }

    public function destroy(Transaction $transaction): Response
    {
        $transaction->delete();

        return response()->noContent();
    }

    private function filtered(Request $request)
    {
        return Transaction::query()
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('projectId'), fn ($q, $v) => $q->where('project_id', $v))
            ->inPeriod('date', ...$this->period($request));
    }
}
