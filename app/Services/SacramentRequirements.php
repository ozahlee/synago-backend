<?php

namespace App\Services;

use App\Models\Baptism;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;

/** The checklist a candidate must satisfy before their baptism / confirmation / dedication is recorded. */
class SacramentRequirements
{
    public const FEES_CATEGORY = 'Sacrament fees';

    /** Requirements that apply to this candidate's rite, merged with what has been ticked off. */
    public function checklist(Baptism $b): array
    {
        $state = $b->requirements ?? [];

        return collect(Setting::get('sacramentRequirements', []))
            ->filter(fn ($r) => in_array($b->kind, $r['kinds'] ?? [], true))
            ->map(function ($r) use ($state, $b) {
                $s = $state[$r['key']] ?? [];
                // the "class" requirement mirrors the existing class_completed flag
                $done = $r['key'] === 'class' ? (bool) $b->class_completed : (bool) ($s['done'] ?? false);

                return [
                    'key' => $r['key'], 'label' => $r['label'], 'fee' => isset($r['fee']) ? (float) $r['fee'] : null,
                    'done' => $done, 'amount' => isset($s['amount']) ? (float) $s['amount'] : null,
                    'note' => $s['note'] ?? null, 'by' => $s['by'] ?? null, 'at' => $s['at'] ?? null,
                    'reference' => $s['reference'] ?? null,
                ];
            })->values()->all();
    }

    /** @return list<string> labels still outstanding */
    public function missing(Baptism $b): array
    {
        return collect($this->checklist($b))->reject(fn ($r) => $r['done'])->pluck('label')->all();
    }

    /** Tick / untick one item. Paying a fee also books it as income under "Sacrament fees". */
    public function mark(Baptism $b, string $key, bool $done, ?float $amount, ?string $note, User $by, ?string $paymentMethod = null): void
    {
        $req = collect(Setting::get('sacramentRequirements', []))->firstWhere('key', $key);
        abort_unless($req && in_array($b->kind, $req['kinds'], true), 422, 'That requirement does not apply to this candidate.');

        if ($key === 'class') {
            $b->class_completed = $done;
        }

        $state = $b->requirements ?? [];
        $entry = $state[$key] ?? [];
        $entry = [...$entry, 'done' => $done, 'note' => $note, 'by' => $by->name, 'at' => now()->toDateString()];

        if ($done && $amount && ($req['fee'] ?? null) !== null && empty($entry['reference'])) {
            $tx = Transaction::create([
                'date' => today(), 'type' => 'income', 'category' => self::FEES_CATEGORY, 'amount' => $amount,
                'payment_method' => $paymentMethod ?? 'Cash', 'description' => ucfirst($b->kind)." fee: {$b->name}",
                'payee' => $b->name, 'recorded_by' => $by->id,
            ]);
            $entry['amount'] = $amount;
            $entry['reference'] = $tx->reference;
        }

        $state[$key] = $entry;
        $b->requirements = $state;
        $b->save();
    }
}
