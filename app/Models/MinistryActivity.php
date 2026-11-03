<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinistryActivity extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $fillable = ['ministry_id', 'title', 'description', 'scheduled_at'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function ministry(): BelongsTo
    {
        return $this->belongsTo(Ministry::class);
    }
}
