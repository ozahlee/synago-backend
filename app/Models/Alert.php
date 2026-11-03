<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    protected $fillable = ['user_id', 'title', 'message', 'type', 'read', 'important', 'archived'];

    protected function casts(): array
    {
        return ['read' => 'boolean', 'important' => 'boolean', 'archived' => 'boolean'];
    }

    /** Alerts addressed to this user or broadcast to everyone. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }
}
