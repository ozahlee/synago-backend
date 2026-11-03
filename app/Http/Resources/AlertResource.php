<?php

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Alert */
class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'date' => $this->created_at->toIso8601String(),
            'read' => $this->read,
            'important' => $this->important,
            'archived' => $this->archived,
        ];
    }
}
