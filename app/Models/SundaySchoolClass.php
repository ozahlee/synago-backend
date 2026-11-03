<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SundaySchoolClass extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    /** Members aged this or younger at registration are enrolled automatically. */
    public const MAX_AGE = 12;

    protected $fillable = ['name', 'min_age', 'max_age', 'room', 'description'];

    protected function casts(): array
    {
        return ['min_age' => 'integer', 'max_age' => 'integer'];
    }

    public function pupils(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(SundaySchoolAttendance::class);
    }

    public static function forAge(int $age): ?self
    {
        return static::where('min_age', '<=', $age)->where('max_age', '>=', $age)->orderBy('min_age')->first();
    }
}
