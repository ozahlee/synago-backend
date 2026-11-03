<?php

namespace App\Models;

use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use GeneratesReference;

    public const KINDS = ['general', 'opening', 'transfer', 'depreciation', 'adjustment'];

    protected $fillable = ['date', 'reference', 'memo', 'kind', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    protected function referencePrefix(): string
    {
        return 'JV';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
