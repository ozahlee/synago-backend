<?php

namespace App\Http\Resources;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Member */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['sundaySchoolClass', 'cellGroup', 'nextOfKin', 'household']);

        return [
            'id' => (string) $this->id,
            'pledgeNumber' => $this->pledge_number,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'dateJoined' => $this->date_joined?->toDateString(),
            'status' => $this->status,
            'sundaySchool' => $this->sunday_school,
            'address' => $this->address,
            'village' => $this->village,
            'parish' => $this->parish,
            'subCounty' => $this->sub_county,
            'dateOfDeath' => $this->date_of_death?->toDateString(),
            'sundaySchoolClassId' => $this->sunday_school_class_id ? (string) $this->sunday_school_class_id : null,
            'sundaySchoolClass' => $this->sundaySchoolClass?->name,
            'cellGroupId' => $this->cell_group_id ? (string) $this->cell_group_id : null,
            'cellGroup' => $this->cellGroup?->name,
            'householdId' => $this->household_id ? (string) $this->household_id : null,
            'household' => $this->household?->name,
            'nextOfKin' => [
                'name' => $this->nextOfKin?->name ?? $this->next_of_kin_name,
                'relationship' => $this->next_of_kin_relationship,
                'phone' => $this->nextOfKin?->phone ?? $this->next_of_kin_phone,
                'memberId' => $this->next_of_kin_member_id ? (string) $this->next_of_kin_member_id : null,
            ],
            'positions' => $this->whenLoaded('positions', fn () => $this->positions->map(fn ($p) => [
                'title' => $p->title, 'scope' => $p->scopeName(), 'startDate' => $p->start_date?->toDateString(),
                'current' => ! $p->end_date || $p->end_date->gte(today()),
            ])),
            // `ministry` is the primary ministry name (dashboard list column); `ministries` is the full set.
            'ministry' => $this->whenLoaded('ministries', fn () => $this->primaryMinistry()?->name),
            'ministries' => $this->whenLoaded('ministries', fn () => $this->ministries->map(fn ($m) => [
                'id' => (string) $m->id,
                'name' => $m->name,
                'isPrimary' => (bool) $m->pivot->is_primary,
            ])),
        ];
    }
}
