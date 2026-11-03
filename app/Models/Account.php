<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['asset', 'liability', 'fund', 'income', 'expense'];

    public const SUBTYPES = ['cash', 'mobile_money', 'bank', 'receivable', 'fixed_asset', 'contra_asset', 'payable', 'loan'];

    protected $fillable = ['code', 'name', 'type', 'subtype', 'description', 'is_system', 'active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'active' => 'boolean'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountMapping::class);
    }

    /** Assets and expenses grow with debits; the rest with credits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
