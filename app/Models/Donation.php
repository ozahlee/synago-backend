<?php

namespace App\Models;

use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use GeneratesReference, HasFactory;

    protected $fillable = [
        'campaign_id', 'member_id', 'donor_name', 'amount', 'date',
        'payment_method', 'reference', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    protected function referencePrefix(): string
    {
        return 'DN';
    }

    public function donorLabel(): string
    {
        return $this->member?->name ?? $this->donor_name ?? 'Anonymous';
    }
}
