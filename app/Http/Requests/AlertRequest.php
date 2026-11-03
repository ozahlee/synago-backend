<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class AlertRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'userId' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'type' => ['sometimes', Rule::in(['info', 'success', 'warning', 'error'])],
            'important' => ['sometimes', 'boolean'],
        ];
    }
}
