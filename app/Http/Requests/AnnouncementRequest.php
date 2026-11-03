<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => [$this->req(), Rule::in(Announcement::TYPES)],
            'title' => [$this->req(), 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'eventAt' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:30'],
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }
}
