<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const SOURCES = ['payment_method', 'offertory', 'income', 'expense'];

    protected $fillable = ['source', 'key', 'account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
