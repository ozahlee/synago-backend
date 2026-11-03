<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    protected $fillable = ['name', 'description', 'target', 'deadline', 'status'];

    protected function casts(): array
    {
        return ['target' => 'decimal:2', 'deadline' => 'date'];
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }
}
