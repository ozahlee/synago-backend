<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transaction */
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('project');

        return [
            'id' => (string) $this->id,
            'date' => $this->date->toDateString(),
            'type' => $this->type,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'paymentMethod' => $this->payment_method,
            'payee' => $this->payee,
            'projectId' => $this->project_id ? (string) $this->project_id : null,
            'project' => $this->project?->name,
            'reference' => $this->reference,
        ];
    }
}
