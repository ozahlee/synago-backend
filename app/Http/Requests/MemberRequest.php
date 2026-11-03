<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class MemberRequest extends ApiRequest
{
    public function rules(): array
    {
        $member = $this->route('member');

        return [
            'pledgeNumber' => ['nullable', 'string', 'max:30', Rule::unique('members', 'pledge_number')->ignore($member)],
            'name' => [$this->req(), 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('members', 'email')->ignore($member)],
            'phone' => ['nullable', 'string', 'max:30'],
            'gender' => [$this->req(), Rule::in(['male', 'female'])],
            'dateOfBirth' => ['nullable', 'date', 'before:today'],
            'dateJoined' => [$this->req(), 'date'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'deceased'])],
            'sundaySchool' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:100'],
            'parish' => ['nullable', 'string', 'max:100'],
            'subCounty' => ['nullable', 'string', 'max:100'],
            'sundaySchoolClassId' => ['nullable', 'integer', 'exists:sunday_school_classes,id'],
            'nextOfKinName' => ['nullable', 'string', 'max:255'],
            'nextOfKinRelationship' => ['nullable', 'string', 'max:50'],
            'nextOfKinPhone' => ['nullable', 'string', 'max:30'],
            'nextOfKinMemberId' => ['nullable', 'integer', 'exists:members,id', Rule::notIn(array_filter([$member?->id]))],
            'cellGroupId' => ['nullable', 'integer', 'exists:cell_groups,id'],
            'householdId' => ['nullable', 'integer', 'exists:households,id'],
            'ministryIds' => ['sometimes', 'array'],
            'ministryIds.*' => ['integer', 'exists:ministries,id'],
            'primaryMinistryId' => ['nullable', 'integer', 'exists:ministries,id'],
        ];
    }
}
