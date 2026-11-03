<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Church geography node: archdeaconry > parish > region (urban) > village. */
class Area extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    /** Lower rank = higher in the tree. A parent must have a lower rank than its child. */
    public const LEVELS = ['archdeaconry' => 1, 'parish' => 2, 'region' => 3, 'village' => 4];

    protected $fillable = ['name', 'level', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Area::class, 'parent_id');
    }

    public function cellGroups(): HasMany
    {
        return $this->hasMany(CellGroup::class);
    }

    /** This area and every ancestor, nearest first. */
    public function lineage(): array
    {
        $chain = [];
        $node = $this;
        $guard = 0;
        while ($node && $guard++ < 10) {
            $chain[] = $node;
            $node = $node->parent;
        }

        return $chain;
    }
}
