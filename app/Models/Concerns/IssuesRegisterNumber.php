<?php

namespace App\Models\Concerns;

/**
 * Completed register entries get "{PREFIX}-{YEAR}-{NNNN}" in `certificate_number` unless one was typed in.
 * Models implement registerPrefix().
 */
trait IssuesRegisterNumber
{
    public static function bootIssuesRegisterNumber(): void
    {
        static::saving(function ($model) {
            if ($model->status === 'completed' && blank($model->certificate_number)) {
                $stem = sprintf('%s-%s-', $model->registerPrefix(), ($model->date ?? now())->format('Y'));
                $last = static::where('certificate_number', 'like', $stem.'%')->orderByDesc('certificate_number')->value('certificate_number');
                $n = $last ? ((int) substr($last, strlen($stem))) + 1 : 1;
                $model->certificate_number = $stem.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    abstract protected function registerPrefix(): string;
}
