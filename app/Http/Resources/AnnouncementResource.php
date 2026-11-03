<?php

namespace App\Http\Resources;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Announcement */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'subject' => $this->subject,
            'details' => $this->details,
            // Local wall-clock time, no timezone: these are church events, read as written.
            'eventAt' => $this->event_at?->format('Y-m-d\TH:i'),
            'venue' => $this->venue,
            'contactName' => $this->contact_name,
            'contactPhone' => $this->contact_phone,
            'memberId' => $this->member_id ? (string) $this->member_id : null,
            'memberName' => $this->whenLoaded('member', fn () => $this->member?->name),
            'pinned' => $this->pinned,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
