<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Outreach;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Outreach: babies homes, hospital wards, prisons, schools, community work. */
class OutreachController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Outreach::with(['project', 'transaction'])
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->inPeriod('date', ...$this->period($request))
            ->latest('date')->latest('id')->paginate($this->perPage($request));
        $year = now()->year;

        return response()->json([
            'data' => collect($rows->items())->map(fn ($o) => $this->present($o)),
            'meta' => $this->meta($rows),
            'stats' => [
                'thisYear' => Outreach::whereYear('date', $year)->count(),
                'peopleReached' => (int) Outreach::whereYear('date', $year)->sum('people_reached'),
                'spent' => round((float) Outreach::whereYear('date', $year)->sum('cost'), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        [$data, $record] = $this->validated($request);
        $o = DB::transaction(function () use ($data, $record, $request) {
            $o = Outreach::create([...$data, 'recorded_by' => $request->user()->id]);
            $this->syncExpense($o, $record, $request);

            return $o;
        });

        return response()->json(['data' => $this->present($o->fresh(['project', 'transaction']))], 201);
    }

    public function update(Request $request, Outreach $outreach): JsonResponse
    {
        [$data, $record] = $this->validated($request, true);
        DB::transaction(function () use ($outreach, $data, $record, $request) {
            $outreach->update($data);
            $this->syncExpense($outreach, $record, $request);
        });

        return response()->json(['data' => $this->present($outreach->fresh(['project', 'transaction']))]);
    }

    public function destroy(Outreach $outreach): Response
    {
        $outreach->delete(); // a posted expense stays in Accounts

        return response()->noContent();
    }

    /** With recordExpense, the cost is posted once as an "Outreach" expense (tagged to the project if any). */
    private function syncExpense(Outreach $o, bool $record, Request $request): void
    {
        if (! $record || ! $o->cost) {
            return;
        }
        if ($o->transaction) {
            $o->transaction->update(['amount' => $o->cost, 'date' => $o->date, 'project_id' => $o->project_id]);

            return;
        }
        $tx = Transaction::create([
            'date' => $o->date, 'type' => 'expense', 'category' => 'Outreach', 'amount' => $o->cost,
            'description' => "Outreach: {$o->title}", 'payee' => $o->place, 'project_id' => $o->project_id,
            'payment_method' => 'Cash', 'recorded_by' => $request->user()->id,
        ]);
        $o->update(['transaction_id' => $tx->id]);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'type' => [$update ? 'sometimes' : 'required', Rule::in(Outreach::TYPES)],
            'place' => ['nullable', 'string', 'max:255'],
            'date' => [$update ? 'sometimes' : 'required', 'date'],
            'peopleReached' => ['nullable', 'integer', 'min:0'],
            'items' => ['nullable', 'string', 'max:5000'],
            'team' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'recordExpense' => ['sometimes', 'boolean'],
        ]);
        $record = (bool) ($v['recordExpense'] ?? false);
        unset($v['recordExpense']);

        return [collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all(), $record];
    }

    private function present(Outreach $o): array
    {
        return [
            'id' => (string) $o->id, 'title' => $o->title, 'type' => $o->type, 'place' => $o->place,
            'date' => $o->date->toDateString(), 'peopleReached' => $o->people_reached, 'items' => $o->items,
            'team' => $o->team, 'cost' => $o->cost !== null ? (float) $o->cost : null,
            'expenseReference' => $o->transaction?->reference, 'projectId' => $o->project_id ? (string) $o->project_id : null,
            'project' => $o->project?->name, 'notes' => $o->notes,
        ];
    }
}
