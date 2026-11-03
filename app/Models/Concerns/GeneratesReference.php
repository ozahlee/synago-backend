<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Fills `reference` on create when the client didn't supply one, e.g. "TH-20261102-7K3QF".
 * Models override referencePrefix() to pick the prefix.
 */
trait GeneratesReference
{
    public static function bootGeneratesReference(): void
    {
        static::creating(function ($model) {
            if (blank($model->reference)) {
                do {
                    $ref = sprintf('%s-%s-%s', $model->referencePrefix(), now()->format('Ymd'), Str::upper(Str::random(5)));
                } while (static::where('reference', $ref)->exists());
                $model->reference = $ref;
            }
        });
    }

    abstract protected function referencePrefix(): string;
}
