<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Community announcements read out in church / shared with the congregation (burials, weddings, meetings…). */
class Announcement extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    public const TYPES = ['burial', 'wedding', 'introduction', 'thanksgiving', 'fundraising', 'meeting', 'general'];

    protected $attributes = ['type' => 'general', 'pinned' => false];

    protected $fillable = [
        'type', 'title', 'subject', 'details', 'event_at', 'venue', 'contact_name', 'contact_phone',
        'member_id', 'pinned', 'created_by',
    ];

    protected function casts(): array
    {
        return ['event_at' => 'datetime', 'pinned' => 'boolean'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    /** Events from today on, plus undated notices posted in the last 30 days. */
    public function scopeUpcoming(Builder $q): void
    {
        $q->where(fn ($q) => $q->where('event_at', '>=', today())
            ->orWhere(fn ($q) => $q->whereNull('event_at')->where('created_at', '>=', now()->subDays(30))));
    }

    public function scopePast(Builder $q): void
    {
        $q->where(fn ($q) => $q->where('event_at', '<', today())
            ->orWhere(fn ($q) => $q->whereNull('event_at')->where('created_at', '<', now()->subDays(30))));
    }
}
