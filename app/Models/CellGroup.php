<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CellGroup extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    protected $fillable = ['name', 'leader_id', 'host', 'village', 'parish', 'meeting_day', 'meeting_time', 'location', 'area_id', 'harvest_target'];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'leader_id')->withTrashed();
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
