<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BudgetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $budgets = Budget::query()
            ->when($request->query('period'), fn ($q, $v) => $q->where('period', $v))
            ->orderByDesc('period')->orderBy('category')
            ->get();

        Budget::hydrateSpent($budgets);

        return BudgetResource::collection($budgets);
    }

    public function store(BudgetRequest $request): BudgetResource
    {
        return new BudgetResource(Budget::create($request->payload()));
    }

    public function show(Budget $budget): BudgetResource
    {
        return new BudgetResource($budget);
    }

    public function update(BudgetRequest $request, Budget $budget): BudgetResource
    {
        $budget->update($request->payload());

        return new BudgetResource($budget);
    }

    public function destroy(Budget $budget): Response
    {
        $budget->delete();

        return response()->noContent();
    }
}
