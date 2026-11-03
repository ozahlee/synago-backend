<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Companies, NGOs, partner churches and others who contribute to projects. */
class Organisation extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const TYPES = ['company', 'ngo', 'church', 'government', 'school', 'other'];

    protected $attributes = ['type' => 'company'];

    protected $fillable = ['name', 'type', 'contact_name', 'phone', 'email', 'notes'];

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }
}
