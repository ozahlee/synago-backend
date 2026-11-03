<?php

namespace App\Http\Resources;

use App\Models\Church;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attrs = $this->resource->getAttributes();
        $church = ! empty($attrs['church_id']) ? Church::find($attrs['church_id']) : null;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'isActive' => $this->is_active,
            'emailVerified' => ! empty($attrs['email_verified_at']),
            'platformAdmin' => (bool) ($attrs['is_platform_admin'] ?? false),
            'roles' => $church ? $this->getRoleNames() : [],
            'permissions' => $church ? $this->getAllPermissions()->pluck('name') : [],
            'church' => $church ? ChurchSummary::make($church) : null,
        ];
    }
}
