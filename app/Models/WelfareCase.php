<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Benevolence request. Paying it posts an expense in the "Welfare" ledger category. */
class WelfareCase extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    public const CATEGORIES = ['bereavement', 'medical', 'school_fees', 'food', 'housing', 'other'];

    public const LEDGER_CATEGORY = 'Welfare';

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'member_id', 'beneficiary', 'category', 'description', 'amount_requested', 'amount_approved', 'status',
        'decided_by', 'decided_at', 'transaction_id', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount_requested' => 'decimal:2', 'amount_approved' => 'decimal:2', 'decided_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
