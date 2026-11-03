<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sermon extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $attributes = ['status' => 'planned'];

    protected $fillable = [
        'date', 'service', 'status', 'preacher_member_id', 'preacher_name', 'preacher_church', 'title', 'theme',
        'sermon_series_id', 'scriptures', 'books', 'notes', 'recording_url', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'scriptures' => 'array', 'books' => 'array'];
    }

    public function preacher(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'preacher_member_id')->withTrashed();
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(SermonSeries::class, 'sermon_series_id');
    }

    public function preacherLabel(): string
    {
        return $this->preacher?->name ?? $this->preacher_name ?? 'To be confirmed';
    }
}
