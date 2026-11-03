<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A church (tenant). Every church-owned row carries its church_id. */
class Church extends Model
{
    public const STATUSES = ['trial', 'active', 'suspended'];

    public const TRIAL_DAYS = 30;

    protected $fillable = ['name', 'slug', 'denomination', 'diocese', 'address', 'phone', 'email', 'country', 'status', 'plan', 'trial_ends_at', 'onboarded_at', 'last_active_at'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'onboarded_at' => 'datetime', 'last_active_at' => 'datetime'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->status !== 'trial' || ! $this->trial_ends_at) {
            return null;
        }

        return (int) max(0, ceil(now()->diffInHours($this->trial_ends_at, false) / 24));
    }

    /** "Saint James Wigua" → "saint-james-wigua", made unique. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'church';
        $slug = $base;
        for ($i = 2; static::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
