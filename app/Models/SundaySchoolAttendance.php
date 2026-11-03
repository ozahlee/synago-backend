<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SundaySchoolAttendance extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $fillable = ['sunday_school_class_id', 'member_id', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
