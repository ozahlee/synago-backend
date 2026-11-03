<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class MinistryRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'name' => [$this->req(), 'string', 'max:255', Rule::unique('ministries')->ignore($this->route('ministry'))],
            'description' => ['nullable', 'string'],
            'leaderId' => ['nullable', 'integer', 'exists:members,id'],
            'meetingDay' => ['nullable', Rule::in(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])],
            'meetingTime' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
