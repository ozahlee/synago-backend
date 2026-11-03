<?php

namespace App\Models;

use App\Models\Concerns\IssuesRegisterNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sacrament / rite register entry: baptism, confirmation (Anglican) or child dedication (Pentecostal).
 * `scheduled` entries are candidates (e.g. in baptism class); `completed` ones get a certificate number.
 */
class Baptism extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory, IssuesRegisterNumber;

    public const KINDS = ['baptism', 'confirmation', 'dedication'];

    public const STATUSES = ['scheduled', 'completed'];

    public const MODES = ['sprinkling', 'pouring', 'immersion'];

    protected $attributes = ['kind' => 'baptism', 'status' => 'scheduled', 'class_completed' => false];

    protected $fillable = [
        'member_id', 'kind', 'status', 'name', 'gender', 'date_of_birth', 'date', 'mode', 'officiant', 'place',
        'father_name', 'mother_name', 'sponsors', 'class_completed', 'requirements', 'override_reason', 'certificate_number', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'date_of_birth' => 'date', 'class_completed' => 'boolean', 'requirements' => 'array'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    protected function registerPrefix(): string
    {
        return match ($this->kind) { 'confirmation' => 'CON', 'dedication' => 'DED', default => 'BAP' };
    }
}
