<?php

namespace App\Http\Resources;

use App\Models\Baptism;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Baptism */
class BaptismResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'memberId' => $this->member_id ? (string) $this->member_id : null,
            'kind' => $this->kind,
            'status' => $this->status,
            'name' => $this->name,
            'gender' => $this->gender,
            'dateOfBirth' => $this->date_of_birth?->toDateString(),
            'date' => $this->date->toDateString(),
            'mode' => $this->mode,
            'officiant' => $this->officiant,
            'place' => $this->place,
            'fatherName' => $this->father_name,
            'motherName' => $this->mother_name,
            'sponsors' => $this->sponsors,
            'classCompleted' => $this->class_completed,
            'certificateNumber' => $this->certificate_number,
            'requirements' => $checklist = app(\App\Services\SacramentRequirements::class)->checklist($this->resource),
            'requirementsDone' => collect($checklist)->where('done', true)->count(),
            'requirementsTotal' => count($checklist),
            'overrideReason' => $this->override_reason,
            'notes' => $this->notes,
        ];
    }
}
