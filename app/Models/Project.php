<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A church project; its spending and income are Accounts transactions tagged with project_id. */
class Project extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const STATUSES = ['planning', 'active', 'on_hold', 'completed'];

    protected $attributes = ['status' => 'active', 'open_for_contributions' => false];

    protected $fillable = [
        'name', 'description', 'budget', 'target', 'status', 'open_for_contributions', 'start_date', 'end_date', 'deadline',
        'suggested_family_amount', 'campaign_id', 'manager_member_id',
    ];

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2', 'target' => 'decimal:2', 'suggested_family_amount' => 'decimal:2', 'open_for_contributions' => 'boolean',
            'start_date' => 'date', 'end_date' => 'date', 'deadline' => 'date',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'manager_member_id')->withTrashed();
    }

    public function spent(): float
    {
        return (float) $this->transactions()->where('type', 'expense')->sum('amount');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('position')->orderBy('due_date');
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class);
    }

    /**
     * Cash raised: every contribution, plus any income entered straight in Accounts and tagged to the
     * project that isn't already one of those contributions.
     */
    public function raised(): float
    {
        $contrib = (float) $this->contributions()->sum('amount');
        $other = (float) $this->transactions()->where('type', 'income')
            ->whereNotIn('id', Contribution::whereNotNull('transaction_id')->select('transaction_id'))->sum('amount');

        return $contrib + $other;
    }

    public function inKindValue(): float
    {
        return (float) $this->contributions()->where('kind', 'in_kind')->sum('in_kind_value');
    }

    /** What the church is aiming to raise (falls back to the budget). */
    public function goal(): float
    {
        return (float) ($this->target ?? $this->budget);
    }
}
