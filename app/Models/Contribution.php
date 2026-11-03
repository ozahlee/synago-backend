<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gift toward a project, from anyone, in any form. It is mirrored once into the church books:
 * gifts → an income transaction tagged to the project; thanksgiving / pledge payments → an offertory record;
 * in-kind gifts → no money record (value only).
 */
class Contribution extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    public const KINDS = ['gift', 'pledge_payment', 'thanksgiving', 'in_kind'];

    public const CONTRIBUTORS = ['member', 'household', 'cell_group', 'ministry', 'organisation', 'named', 'anonymous'];

    protected $fillable = [
        'project_id', 'date', 'kind', 'contributor_type', 'member_id', 'household_id', 'cell_group_id', 'ministry_id',
        'organisation_id', 'contributor_name', 'amount', 'in_kind_description', 'in_kind_value', 'payment_method',
        'reference', 'service', 'event_id', 'pledge_id', 'offertory_id', 'transaction_id', 'hide_name', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'in_kind_value' => 'decimal:2', 'hide_name' => 'boolean'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function member(): BelongsTo { return $this->belongsTo(Member::class)->withTrashed(); }

    public function household(): BelongsTo { return $this->belongsTo(Household::class); }

    public function cellGroup(): BelongsTo { return $this->belongsTo(CellGroup::class); }

    public function ministry(): BelongsTo { return $this->belongsTo(Ministry::class); }

    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }

    public function event(): BelongsTo { return $this->belongsTo(Event::class); }

    public function pledge(): BelongsTo { return $this->belongsTo(Pledge::class); }

    public const RELATIONS = ['member.cellGroup', 'household.head', 'cellGroup.leader', 'ministry', 'organisation', 'event'];

    public function contributorLabel(): string
    {
        return match ($this->contributor_type) {
            'member' => $this->member?->name,
            'household' => $this->household?->name,
            'cell_group' => $this->cellGroup?->name,
            'ministry' => $this->ministry?->name,
            'organisation' => $this->organisation?->name,
            'named' => $this->contributor_name,
            default => null,
        } ?? 'Anonymous';
    }

    /** Which cell group the gift counts for in the cell league. */
    public function cellGroupId(): ?int
    {
        return match ($this->contributor_type) {
            'cell_group' => $this->cell_group_id,
            'member' => $this->member?->cell_group_id,
            'household' => $this->household?->head?->cell_group_id,
            default => null,
        };
    }

    public function contactPhone(): ?string
    {
        return match ($this->contributor_type) {
            'member' => $this->member?->phone,
            'household' => $this->household?->contactPhone(),
            'organisation' => $this->organisation?->phone,
            'cell_group' => $this->cellGroup?->leader?->phone,
            default => null,
        };
    }
}
