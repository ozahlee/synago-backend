<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Head count for one service on one day. */
class Attendance extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    protected $fillable = ['date', 'service', 'men', 'women', 'children', 'visitors', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'men' => 'integer', 'women' => 'integer', 'children' => 'integer', 'visitors' => 'integer'];
    }

    public function total(): int
    {
        return $this->men + $this->women + $this->children + $this->visitors;
    }
}
