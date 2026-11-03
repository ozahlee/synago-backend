<?php

namespace App\Http\Requests;

class MinistryActivityRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'scheduledAt' => ['nullable', 'date'],
        ];
    }
}
