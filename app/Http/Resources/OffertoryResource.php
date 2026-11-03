<?php

namespace App\Http\Resources;

use App\Models\Offertory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Offertory */
class OffertoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('member');

        return [
            'id' => (string) $this->id,
            'date' => $this->date->toDateString(),
            'service' => $this->service,
            'category' => $this->category,
            'amount' => (float) $this->amount,
            'paymentMethod' => $this->payment_method,
            'reference' => $this->reference,
            'pledgeNumber' => $this->member?->pledge_number,
            'cellGroupId' => $this->cell_group_id ? (string) $this->cell_group_id : null,
            'memberId' => $this->member_id ? (string) $this->member_id : null,
            'contributor' => $this->contributorLabel(),
            'notes' => $this->notes,
        ];
    }
}
