<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $attributes = ['status' => 'pending', 'channel' => 'sms', 'sent_count' => 0];

    protected $fillable = [
        'remind_at', 'channel', 'audience', 'audience_label', 'message', 'status', 'source', 'source_id',
        'dedupe_key', 'sent_count', 'note', 'sms_batch_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'audience' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
