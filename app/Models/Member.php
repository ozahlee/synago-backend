<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory, SoftDeletes;

    protected $attributes = ['status' => 'active', 'sunday_school' => false];

    protected $fillable = [
        'pledge_number', 'name', 'email', 'phone', 'gender', 'date_of_birth',
        'date_joined', 'status', 'sunday_school', 'address', 'village', 'parish', 'sub_county', 'cell_group_id',
        'sunday_school_class_id', 'household_id', 'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone', 'next_of_kin_member_id', 'date_of_death',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_joined' => 'date',
            'date_of_death' => 'date',
            'sunday_school' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member) {
            if (blank($member->pledge_number)) {
                $member->pledge_number = static::nextPledgeNumber();
            }
        });

        // Children (12 and under) go to Sunday school automatically, in the class for their age.
        static::saving(function (Member $member) {
            if (! $member->isDirty('date_of_birth') && $member->exists) {
                return;
            }
            $age = $member->date_of_birth?->age;
            if ($age === null || $member->status === 'deceased') {
                return;
            }
            if ($age <= SundaySchoolClass::MAX_AGE) {
                $member->sunday_school = true;
                $member->sunday_school_class_id ??= SundaySchoolClass::forAge($age)?->id;
            }
        });
    }

    /** Next free "{prefix}-NNNN" pledge / envelope number. */
    public static function nextPledgeNumber(): string
    {
        $prefix = Setting::get('pledgePrefix');
        $max = static::withTrashed()->where('pledge_number', 'like', "{$prefix}-%")->pluck('pledge_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix) + 1))->max() ?? 0;

        return sprintf('%s-%04d', $prefix, $max + 1);
    }

    public function ministries(): BelongsToMany
    {
        return $this->belongsToMany(Ministry::class)
            ->withPivot(['is_primary', 'joined_at'])
            ->withTimestamps();
    }

    public function primaryMinistry(): ?Ministry
    {
        return $this->ministries->firstWhere('pivot.is_primary', true) ?? $this->ministries->first();
    }

    public function offertories(): HasMany
    {
        return $this->hasMany(Offertory::class);
    }

    public function baptisms(): HasMany
    {
        return $this->hasMany(Baptism::class);
    }

    public function cellGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CellGroup::class);
    }

    public function sundaySchoolClass(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SundaySchoolClass::class);
    }

    public function nextOfKin(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Member::class, 'next_of_kin_member_id')->withTrashed();
    }

    public function household(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class);
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }
}
