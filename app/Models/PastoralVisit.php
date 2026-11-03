<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PastoralVisit extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['home', 'hospital', 'sick', 'bereavement', 'newborn', 'prison', 'other'];

    protected $fillable = ['care_case_id', 'member_id', 'visitee', 'type', 'date', 'place', 'team', 'notes', 'gift_amount', 'follow_up_on', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'follow_up_on' => 'date', 'gift_amount' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function careCase(): BelongsTo
    {
        return $this->belongsTo(CareCase::class);
    }

    public function visiteeLabel(): string
    {
        return $this->member?->name ?? $this->visitee ?? '—';
    }
}
