<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsBatch extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $fillable = ['message', 'audience', 'audience_label', 'recipient_count', 'sent_count', 'failed_count', 'driver', 'sent_by'];

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
