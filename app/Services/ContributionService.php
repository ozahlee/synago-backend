<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sms\SmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Records project contributions and keeps the church books in step (each gift lands in exactly one ledger). */
class ContributionService
{
    public function __construct(private SmsService $sms) {}

    public function record(Project $project, array $d, User $by, bool $sendThanks = false, ?string $phone = null): Contribution
    {
        $c = DB::transaction(function () use ($project, $d, $by) {
            if ($d['kind'] === 'pledge_payment') {
                $pledge = Pledge::findOrFail($d['pledge_id'] ?? 0);
                if ((int) $pledge->project_id !== $project->id) {
                    throw ValidationException::withMessages(['pledgeId' => 'That pledge is not for this project.']);
                }
                // the pledger is the contributor
                $d = [...$d, 'contributor_type' => $pledge->member_id ? 'member' : ($pledge->pledger_name ? 'named' : 'anonymous'),
                    'member_id' => $pledge->member_id, 'contributor_name' => $pledge->member_id ? null : $pledge->pledger_name];
            }
            if ($d['kind'] === 'in_kind') {
                $d['amount'] = 0;
            }

            $c = new Contribution([...$d, 'project_id' => $project->id, 'recorded_by' => $by->id]);
            $c->reference = $d['reference'] ?? $this->nextReference();
            $c->load(Contribution::RELATIONS); // for the contributor label
            $label = $c->contributorLabel();

            if ($c->kind === 'gift') {
                $c->transaction_id = Transaction::create([
                    'date' => $c->date, 'type' => 'income', 'category' => 'Project contributions', 'project_id' => $project->id,
                    'amount' => $c->amount, 'payment_method' => $c->payment_method, 'description' => "Contribution to {$project->name}",
                    'payee' => $label, 'reference' => $c->reference, 'recorded_by' => $by->id,
                ])->id;
            } elseif (in_array($c->kind, ['thanksgiving', 'pledge_payment'], true)) {
                $c->offertory_id = Offertory::create([
                    'date' => $c->date, 'service' => $c->service, 'category' => $c->kind === 'thanksgiving' ? 'thanksgiving' : 'pledge',
                    'amount' => $c->amount, 'payment_method' => $c->payment_method ?? 'Cash', 'reference' => $c->reference,
                    'member_id' => $c->contributor_type === 'member' ? $c->member_id : null,
                    'cell_group_id' => $c->contributor_type === 'cell_group' ? $c->cell_group_id : null,
                    'contributor_name' => in_array($c->contributor_type, ['member', 'cell_group', 'anonymous'], true) ? null : $label,
                    'pledge_id' => $c->pledge_id, 'notes' => "Toward {$project->name}", 'recorded_by' => $by->id,
                ])->id;
            }
            $c->save();

            return $c;
        });

        if ($sendThanks) {
            $this->thank($c->fresh(Contribution::RELATIONS), $project, $by, $phone);
        }

        return $c;
    }

    /** Removing a contribution also removes the money record it created. */
    public function delete(Contribution $c): void
    {
        DB::transaction(function () use ($c) {
            $tx = $c->transaction_id;
            $off = $c->offertory_id;
            $c->delete();
            if ($tx) {
                Transaction::whereKey($tx)->delete();
            }
            if ($off) {
                Offertory::whereKey($off)->delete();
            }
        });
    }

    /** Thank-you SMS with the receipt number. */
    public function thank(Contribution $c, Project $project, User $by, ?string $phone = null): bool
    {
        $phone = $c->contactPhone() ?? $phone;
        if (! $phone) {
            return false;
        }
        $what = $c->kind === 'in_kind' ? ($c->in_kind_description ?? 'gift in kind') : number_format((float) $c->amount).' '.config('church.currency');
        $msg = "Dear {name}, thank you for your contribution of {$what} to {$project->name} (receipt {$c->reference}). May God bless you abundantly. {church}";
        $name = $c->contributorLabel();
        $batch = $this->sms->send(['type' => 'numbers', 'numbers' => $phone], str_replace('{name}', Str::of($name)->replace('The ', '')->explode(' ')->first(), $msg), $by);

        return $batch->sent_count > 0;
    }

    private function nextReference(): string
    {
        do {
            $ref = 'CTB-'.now(config('church.timezone'))->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Contribution::where('reference', $ref)->exists() || Transaction::where('reference', $ref)->exists() || Offertory::where('reference', $ref)->exists());

        return $ref;
    }
}
