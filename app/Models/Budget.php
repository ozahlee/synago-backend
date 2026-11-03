<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A monthly allocation for an expense category. "spent" is derived from
 * expense transactions with the same category within the period.
 */
class Budget extends Model
{
    use \App\Models\Concerns\BelongsToChurch;

    use HasFactory;

    public const WARNING_THRESHOLD = 0.85;

    protected $fillable = ['category', 'allocated', 'period', 'notes'];

    protected function casts(): array
    {
        return ['allocated' => 'decimal:2'];
    }

    /**
     * Load "spent" for many budgets with one query per distinct period
     * (portable across SQLite/MySQL, avoids N+1 on list endpoints).
     *
     * @param  Collection<int, Budget>  $budgets
     */
    public static function hydrateSpent(Collection $budgets): void
    {
        foreach ($budgets->groupBy('period') as $period => $group) {
            [$start, $end] = self::periodRange($period);

            $totals = Transaction::query()
                ->where('type', 'expense')
                ->whereIn('category', $group->pluck('category')->unique())
                ->whereDate('date', '>=', $start)
                ->whereDate('date', '<=', $end)
                ->groupBy('category')
                ->selectRaw('category, SUM(amount) as total')
                ->pluck('total', 'category');

            $group->each(fn (Budget $b) => $b->setAttribute('spent', round((float) ($totals[$b->category] ?? 0), 2)));
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function periodRange(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()];
    }

    public function spentAmount(): float
    {
        if (array_key_exists('spent', $this->attributes)) {
            return (float) $this->attributes['spent'];
        }

        [$start, $end] = self::periodRange($this->period);

        return (float) Transaction::where('type', 'expense')
            ->where('category', $this->category)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->sum('amount');
    }

    public function status(): string
    {
        $allocated = (float) $this->allocated;
        $ratio = $allocated > 0 ? $this->spentAmount() / $allocated : 1;

        return match (true) {
            $ratio > 1 => 'exceeded',
            $ratio >= self::WARNING_THRESHOLD => 'warning',
            default => 'on-track',
        };
    }
}
