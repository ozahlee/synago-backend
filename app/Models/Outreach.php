<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Outreach extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['babies_home', 'hospital', 'prison', 'school', 'community', 'other'];

    protected $fillable = ['title', 'type', 'place', 'date', 'people_reached', 'items', 'team', 'cost', 'transaction_id', 'project_id', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cost' => 'decimal:2', 'people_reached' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
