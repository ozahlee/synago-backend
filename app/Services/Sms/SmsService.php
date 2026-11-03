<?php

namespace App\Services\Sms;

use App\Models\Member;
use App\Models\Setting;
use App\Models\SmsBatch;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Phone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Resolves an audience to phone numbers, personalises, sends and logs a batch. */
class SmsService
{
    public function __construct(private SmsGateway $gateway) {}

    /**
     * @param  array{type: string, ministryId?: int, cellGroupId?: int, gender?: string, memberIds?: int[], numbers?: string}  $audience
     * @return array{label: string, recipients: Collection<int, array{member_id: ?int, name: ?string, phone: string}>, skipped: int}
     */
    public function resolve(array $audience): array
    {
        $type = $audience['type'];
        $active = Member::query()->active();

        [$label, $people] = match ($type) {
            'all' => ['All active members', $active->get()],
            'ministry' => (function () use ($active, $audience) {
                $m = \App\Models\Ministry::findOrFail($audience['ministryId']);

                return ["{$m->name} ministry", $active->whereRelation('ministries', 'ministries.id', $m->id)->get()];
            })(),
            'cell_group' => (function () use ($active, $audience) {
                $g = \App\Models\CellGroup::findOrFail($audience['cellGroupId']);

                return ["{$g->name} cell group", $active->where('cell_group_id', $g->id)->get()];
            })(),
            'gender' => [$audience['gender'] === 'female' ? 'Women' : 'Men', $active->where('gender', $audience['gender'])->get()],
            'birthdays' => ['Birthdays today', $active->whereNotNull('date_of_birth')->get()
                ->filter(fn (Member $m) => $m->date_of_birth->format('m-d') === now()->format('m-d'))],
            'visitors' => ['Recent visitors', Visitor::whereIn('status', ['new', 'contacted', 'returning'])->get()],
            'members' => ['Selected members', Member::whereIn('id', $audience['memberIds'] ?? [])->get()],
            'staff' => ['Church staff', \App\Models\User::where('is_active', true)->get()],
            'leaders' => ['Church leaders', Member::active()->whereIn('id', \App\Models\Position::current()->select('member_id'))->get()],
            'numbers' => ['Typed numbers', collect(preg_split('/[\s,;]+/', $audience['numbers'] ?? '', -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($n) => (object) ['id' => null, 'name' => null, 'phone' => $n])],
        };

        $recipients = [];
        $skipped = 0;
        foreach ($people as $p) {
            $phone = Phone::normalize($p->phone);
            if (! $phone) {
                $skipped++;

                continue;
            }
            $recipients[$phone] ??= [
                'member_id' => $p instanceof Member ? $p->id : null,
                'name' => $p->name,
                'phone' => $phone,
            ];
        }

        return ['label' => $label, 'recipients' => collect(array_values($recipients)), 'skipped' => $skipped];
    }

    /** {name} → first name, {church} → church name. */
    public function personalise(string $message, ?string $name): string
    {
        return strtr($message, [
            '{name}' => $name ? Str::of($name)->explode(' ')->first() : 'friend',
            '{church}' => Setting::get('churchName'),
        ]);
    }

    public function send(array $audience, string $message, User $by): SmsBatch
    {
        ['label' => $label, 'recipients' => $recipients] = $this->resolve($audience);

        $batch = SmsBatch::create([
            'message' => $message, 'audience' => $audience['type'], 'audience_label' => $label,
            'recipient_count' => $recipients->count(), 'driver' => $this->gateway->name(), 'sent_by' => $by->id,
        ]);

        $personal = str_contains($message, '{name}');
        // Personal messages go one by one; otherwise one bulk call per 100 numbers.
        $groups = $personal
            ? $recipients->map(fn ($r) => [$this->personalise($message, $r['name']), [$r]])
            : collect([[$this->personalise($message, null), $recipients->all()]]);

        foreach ($groups as [$text, $group]) {
            $group = collect($group);
            if ($group->isEmpty()) {
                continue;
            }
            $results = $this->gateway->send($group->pluck('phone')->all(), $text);
            foreach ($group as $r) {
                $res = $results[$r['phone']] ?? ['ok' => false, 'id' => null, 'error' => 'No response'];
                $batch->messages()->create([
                    'member_id' => $r['member_id'], 'name' => $r['name'], 'phone' => $r['phone'],
                    'status' => $res['ok'] ? 'sent' : 'failed', 'provider_id' => $res['id'], 'error' => $res['error'],
                ]);
            }
        }

        $batch->update([
            'sent_count' => $batch->messages()->where('status', 'sent')->count(),
            'failed_count' => $batch->messages()->where('status', 'failed')->count(),
        ]);

        return $batch;
    }

    public function driver(): string
    {
        return $this->gateway->name();
    }
}
