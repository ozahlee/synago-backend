<?php

namespace App\Http\Requests;

use App\Models\Offertory;
use Illuminate\Validation\Rule;

class OffertoryRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'date' => [$this->req(), 'date'],
            'service' => ['nullable', 'string', 'max:50'],
            'category' => [$this->req(), Rule::in(Offertory::CATEGORIES)],
            'amount' => [$this->req(), 'numeric', 'gt:0', 'max:999999999999'],
            'paymentMethod' => [$this->req(), 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:50', Rule::unique('offertories')->ignore($this->route('offertory'))],
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'cellGroupId' => ['nullable', 'integer', 'exists:cell_groups,id'],
            'contributorName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
