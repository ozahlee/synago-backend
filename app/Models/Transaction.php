<?php

namespace App\Models;

use App\Models\Concerns\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use GeneratesReference, HasFactory;

    protected $fillable = ['date', 'type', 'category', 'project_id', 'amount', 'payment_method', 'description', 'payee', 'reference', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2'];
    }

    protected function referencePrefix(): string
    {
        return $this->type === 'income' ? 'INC' : 'EXP';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
