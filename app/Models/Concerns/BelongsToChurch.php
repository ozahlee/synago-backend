<?php

namespace App\Models\Concerns;

use App\Models\Church;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Church-owned data: queries only ever see the current church's rows, and new rows are stamped
 * with it. This is the line that keeps one church from seeing another's records.
 */
trait BelongsToChurch
{
    public static function bootBelongsToChurch(): void
    {
        static::addGlobalScope('church', function (Builder $q) {
            if ($id = Tenant::id()) {
                $q->where($q->getModel()->qualifyColumn('church_id'), $id);
            }
        });

        static::creating(function ($model) {
            if (empty($model->church_id) && Tenant::id()) {
                $model->church_id = Tenant::id();
            }
        });
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class);
    }
}
