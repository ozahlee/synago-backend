<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class TransactionRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'date' => [$this->req(), 'date'],
            'type' => [$this->req(), Rule::in(['income', 'expense'])],
            'category' => [$this->req(), 'string', 'max:100'],
            'amount' => [$this->req(), 'numeric', 'gt:0', 'max:999999999999'],
            'description' => [$this->req(), 'string', 'max:255'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'payee' => ['nullable', 'string', 'max:255'],
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'reference' => ['nullable', 'string', 'max:50', Rule::unique('transactions')->ignore($this->route('transaction'))],
        ];
    }
}
