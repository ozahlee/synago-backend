<?php

namespace App\Models;

use App\Models\Concerns\IssuesRegisterNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Marriage extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory, IssuesRegisterNumber;

    protected $attributes = ['status' => 'scheduled', 'banns_read' => 0, 'counselling_done' => false];

    protected $fillable = [
        'status', 'groom_name', 'groom_member_id', 'bride_name', 'bride_member_id', 'date', 'place', 'officiant',
        'witnesses', 'banns_read', 'counselling_done', 'certificate_number', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'banns_read' => 'integer', 'counselling_done' => 'boolean'];
    }

    protected function registerPrefix(): string
    {
        return 'MAR';
    }

    public function groom(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'groom_member_id')->withTrashed();
    }

    public function bride(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'bride_member_id')->withTrashed();
    }
}
