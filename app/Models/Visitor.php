<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    public const STATUSES = ['new', 'contacted', 'returning', 'joined', 'lost'];

    public const STEPS = ['welcome_sent', 'called', 'visited', 'invited_back'];

    protected $attributes = ['status' => 'new', 'welcome_sent' => false, 'called' => false, 'visited' => false, 'invited_back' => false];

    protected $fillable = [
        'name', 'phone', 'email', 'gender', 'village', 'first_visit', 'service', 'invited_by', 'how_heard',
        'prayer_request', 'status', 'welcome_sent', 'called', 'visited', 'invited_back', 'assigned_to', 'member_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'first_visit' => 'date', 'welcome_sent' => 'boolean', 'called' => 'boolean',
            'visited' => 'boolean', 'invited_back' => 'boolean',
        ];
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(VisitorFollowUp::class)->latest('date')->latest('id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }
}
