<?php

namespace App\Http\Resources;

use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Budget */
class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'category' => $this->category,
            'allocated' => (float) $this->allocated,
            'spent' => $this->spentAmount(),
            'period' => $this->period,
            'status' => $this->status(),
            'notes' => $this->notes,
        ];
    }
}
