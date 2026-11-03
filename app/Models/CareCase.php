<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pastoral care case: a member's death, a bereavement in a member's family, sickness, a newborn… */
class CareCase extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['member_death', 'bereavement', 'sickness', 'hospital', 'newborn', 'other'];

    protected $attributes = ['status' => 'open'];

    protected $fillable = [
        'type', 'member_id', 'subject', 'relationship', 'date', 'burial_date', 'burial_place', 'status',
        'welfare_case_id', 'announcement_id', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'burial_date' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(PastoralVisit::class)->latest('date');
    }

    public function welfareCase(): BelongsTo
    {
        return $this->belongsTo(WelfareCase::class);
    }
}
