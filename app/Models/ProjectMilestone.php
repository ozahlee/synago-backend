<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A payment or works stage: deposit, balance, survey, title transfer; foundation, walls, roofing… */
class ProjectMilestone extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['project_id', 'title', 'amount', 'due_date', 'status', 'done_on', 'notes', 'position'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'date', 'done_on' => 'date'];
    }
}
