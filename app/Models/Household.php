<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A family: a head of household plus the members living with them. */
class Household extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $fillable = ['name', 'head_member_id', 'phone', 'village', 'notes'];

    public function head(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'head_member_id')->withTrashed();
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function contactPhone(): ?string
    {
        return $this->phone ?? $this->head?->phone;
    }
}
