<?php

namespace App\Models;

use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offertory extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use GeneratesReference, HasFactory;

    public const CATEGORIES = ['tithe', 'pledge', 'thanksgiving', 'harvest'];

    protected $fillable = [
        'date', 'service', 'category', 'amount', 'payment_method', 'reference',
        'member_id', 'pledge_id', 'cell_group_id', 'contributor_name', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function referencePrefix(): string
    {
        return match ($this->category) {
            'tithe' => 'TH', 'pledge' => 'PL', 'thanksgiving' => 'TG', 'harvest' => 'HV', default => 'OF',
        };
    }

    public function cellGroup(): BelongsTo
    {
        return $this->belongsTo(CellGroup::class);
    }

    public function contributorLabel(): string
    {
        if ($this->member_id && ! $this->relationLoaded('member')) {
            $this->loadMissing('member');
        }
        if (! $this->member && ! $this->contributor_name && $this->cell_group_id) {
            return $this->loadMissing('cellGroup')->cellGroup?->name ?? 'Anonymous';
        }

        return $this->member?->name ?? $this->contributor_name ?? 'Anonymous';
    }
}
