<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A church-specific day in the church year: fixed date (e.g. patronal festival) or relative to Easter. */
class Observance extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const COLOURS = ['white', 'gold', 'red', 'purple', 'rose', 'green', 'black', 'blue'];

    protected $fillable = ['name', 'rule', 'month', 'day', 'easter_offset', 'duration_days', 'colour', 'note'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'day' => 'integer', 'easter_offset' => 'integer', 'duration_days' => 'integer'];
    }
}
