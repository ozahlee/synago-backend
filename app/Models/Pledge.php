<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A promise to give; payments are offertory records (category "pledge") pointing back here. */
class Pledge extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    protected $fillable = ['member_id', 'pledger_name', 'campaign_id', 'project_id', 'purpose', 'amount', 'pledge_date', 'due_date', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'pledge_date' => 'date', 'due_date' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Offertory::class);
    }

    public function pledgerLabel(): string
    {
        return $this->member?->name ?? $this->pledger_name ?? 'Anonymous';
    }

    public function paid(): float
    {
        return (float) ($this->payments_sum_amount ?? $this->payments()->sum('amount'));
    }

    /** fulfilled | overdue | open */
    public function status(): string
    {
        if ($this->paid() >= (float) $this->amount) {
            return 'fulfilled';
        }

        return $this->due_date && $this->due_date->isPast() && ! $this->due_date->isToday() ? 'overdue' : 'open';
    }
}
