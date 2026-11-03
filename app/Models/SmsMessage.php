<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsMessage extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $fillable = ['sms_batch_id', 'member_id', 'name', 'phone', 'status', 'provider_id', 'error'];
}
