<?php

namespace App\Http\Resources;

use App\Models\Ministry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ministry */
class MinistryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'leaderId' => $this->leader_id ? (string) $this->leader_id : null,
            'leader' => $this->whenLoaded('leader', fn () => $this->leader?->name),
            'members' => $this->whenCounted('members'),
            'meetingDay' => $this->meeting_day,
            'meetingTime' => $this->meeting_time ? substr($this->meeting_time, 0, 5) : null,
            'location' => $this->location,
            'status' => $this->status,
            'memberList' => $this->whenLoaded('members', fn () => $this->members->map(fn ($m) => [
                'id' => (string) $m->id,
                'name' => $m->name,
                'joinedAt' => $m->pivot->joined_at,
            ])),
            'activities' => $this->whenLoaded('activities', fn () => $this->activities->map(fn ($a) => [
                'id' => (string) $a->id,
                'title' => $a->title,
                'description' => $a->description,
                'scheduledAt' => $a->scheduled_at?->toIso8601String(),
            ])),
        ];
    }
}
