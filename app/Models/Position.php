<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An office held by a member: "Sunday school teacher" of a class, "Chairperson" of a ministry, "Leader" of a cell… */
class Position extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const SCOPES = [
        'church' => null,
        'ministry' => Ministry::class,
        'cell_group' => CellGroup::class,
        'sunday_school_class' => SundaySchoolClass::class,
        'project' => Project::class,
    ];

    protected $fillable = ['member_id', 'title', 'scope_type', 'scope_id', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function scopeCurrent(Builder $q): void
    {
        $q->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today()));
    }

    public function scopeModel(): ?Model
    {
        $class = self::SCOPES[$this->scope_type] ?? null;

        return $class && $this->scope_id ? $class::find($this->scope_id) : null;
    }

    public function scopeName(): string
    {
        return $this->scope_type === 'church' ? 'Whole church' : ($this->scopeModel()?->name ?? 'Removed group');
    }
}
