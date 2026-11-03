<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SermonSeries extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    protected $table = 'sermon_series';

    protected $fillable = ['name', 'description'];

    public function sermons(): HasMany
    {
        return $this->hasMany(Sermon::class);
    }
}
