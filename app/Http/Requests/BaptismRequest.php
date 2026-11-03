<?php

namespace App\Http\Requests;

use App\Models\Baptism;
use Illuminate\Validation\Rule;

class BaptismRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'kind' => [$this->req(), Rule::in(Baptism::KINDS)],
            'status' => ['sometimes', Rule::in(Baptism::STATUSES)],
            'name' => [$this->isUpdate() ? 'sometimes' : 'required_without:memberId', 'nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'dateOfBirth' => ['nullable', 'date', \App\Support\ChurchDate::notFuture()],
            'date' => [$this->req(), 'date'],
            'mode' => ['nullable', Rule::in(Baptism::MODES)],
            'officiant' => ['nullable', 'string', 'max:255'],
            'place' => ['nullable', 'string', 'max:255'],
            'fatherName' => ['nullable', 'string', 'max:255'],
            'motherName' => ['nullable', 'string', 'max:255'],
            'sponsors' => ['nullable', 'string', 'max:255'],
            'classCompleted' => ['sometimes', 'boolean'],
            'certificateNumber' => ['nullable', 'string', 'max:30', Rule::unique('baptisms', 'certificate_number')->ignore($this->route('baptism'))],
            'notes' => ['nullable', 'string'],
            'overrideReason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
