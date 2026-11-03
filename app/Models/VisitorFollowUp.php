<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorFollowUp extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const METHODS = ['call', 'visit', 'sms', 'whatsapp', 'in_person'];

    protected $fillable = ['visitor_id', 'user_id', 'method', 'note', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
