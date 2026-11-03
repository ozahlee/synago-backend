<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\CareCase;
use App\Models\Member;
use App\Models\PastoralVisit;
use App\Models\WelfareCase;
use App\Services\Sms\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Bereavement, sickness, newborns and the visits made to care for people. */
class PastoralCareController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json(['data' => [
            'openCases' => CareCase::where('status', 'open')->count(),
            'deathsThisYear' => Member::where('status', 'deceased')->whereYear('date_of_death', now()->year)->count(),
            'visitsThisMonth' => PastoralVisit::whereDateBetween('date', [now()->startOfMonth()->toDateString(), today()->toDateString()])->count(),
            // follow-ups falling due in the coming week (or missed in the last month)
            'followUpsDue' => PastoralVisit::whereBetween('follow_up_on', [now()->subDays(30)->toDateString(), now()->addDays(7)->toDateString()])->count(),
        ]]);
    }

    public function cases(Request $request): JsonResponse
    {
        $rows = CareCase::with(['member', 'welfareCase'])->withCount('visits')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->inPeriod('date', ...$this->period($request))
            ->latest('date')->latest('id')->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($c) => $this->presentCase($c)), 'meta' => $this->meta($rows)]);
    }

    public function showCase(CareCase $case): JsonResponse
    {
        $case->load(['member', 'welfareCase', 'visits.member'])->loadCount('visits');

        return response()->json(['data' => $this->presentCase($case) + [
            'visits' => $case->visits->map(fn ($v) => $this->presentVisit($v)),
        ]]);
    }

    public function storeCase(Request $request): JsonResponse
    {
        $c = CareCase::create([...$this->caseData($request), 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->presentCase($c->load('member'))], 201);
    }

    public function updateCase(Request $request, CareCase $case): JsonResponse
    {
        $case->update($this->caseData($request, true));

        return response()->json(['data' => $this->presentCase($case->fresh(['member', 'welfareCase']))]);
    }

    public function destroyCase(CareCase $case): Response
    {
        $case->delete();

        return response()->noContent();
    }

    public function visits(Request $request): JsonResponse
    {
        $rows = PastoralVisit::with('member')
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->unless($request->boolean('followUps'), fn ($q) => $q->inPeriod('date', ...$this->period($request)))
            ->when($request->boolean('followUps'), fn ($q) => $q->whereNotNull('follow_up_on')->orderBy('follow_up_on'),
                fn ($q) => $q->latest('date')->latest('id'))
            ->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($v) => $this->presentVisit($v)), 'meta' => $this->meta($rows)]);
    }

    public function storeVisit(Request $request): JsonResponse
    {
        $v = PastoralVisit::create([...$this->visitData($request), 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->presentVisit($v->load('member'))], 201);
    }

    public function updateVisit(Request $request, PastoralVisit $visit): JsonResponse
    {
        $visit->update($this->visitData($request, true));

        return response()->json(['data' => $this->presentVisit($visit->fresh('member'))]);
    }

    public function destroyVisit(PastoralVisit $visit): Response
    {
        $visit->delete();

        return response()->noContent();
    }

    /**
     * Record that a member has died: marks them deceased (kept in the records, dropped from active lists
     * and SMS), opens a care case, and optionally posts the burial announcement, opens a welfare case for
     * the family and texts the next of kin.
     */
    public function recordDeath(Request $request, Member $member, SmsService $sms): JsonResponse
    {
        abort_if($member->status === 'deceased', 422, "{$member->name} is already recorded as deceased.");
        $v = $request->validate([
            'dateOfDeath' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'burialDate' => ['nullable', 'date', 'after_or_equal:dateOfDeath'],
            'burialPlace' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'announce' => ['sometimes', 'boolean'],
            'openWelfare' => ['sometimes', 'boolean'],
            'welfareAmount' => ['nullable', 'numeric', 'gt:0'],
            'smsNextOfKin' => ['sometimes', 'boolean'],
            'smsMessage' => ['nullable', 'string', 'max:459'],
        ]);

        $case = DB::transaction(function () use ($member, $v, $request) {
            $member->update(['status' => 'deceased', 'date_of_death' => $v['dateOfDeath'], 'sunday_school' => false, 'sunday_school_class_id' => null]);
            $member->positions()->current()->update(['end_date' => $v['dateOfDeath']]);
            $family = $member->nextOfKin?->name ?? $member->next_of_kin_name;

            $announcement = ($v['announce'] ?? false) ? Announcement::create([
                'type' => 'burial', 'title' => "Burial of the late {$member->name}", 'subject' => "The late {$member->name}",
                'event_at' => isset($v['burialDate']) ? $v['burialDate'].' 11:00' : null, 'venue' => $v['burialPlace'] ?? null,
                'contact_name' => $family, 'contact_phone' => $member->nextOfKin?->phone ?? $member->next_of_kin_phone,
                'member_id' => $member->id, 'pinned' => true, 'created_by' => $request->user()->id,
                'details' => $v['notes'] ?? null,
            ]) : null;

            $welfare = ($v['openWelfare'] ?? false) ? WelfareCase::create([
                'member_id' => $member->next_of_kin_member_id, 'beneficiary' => $family ? "{$family} (family of the late {$member->name})" : "Family of the late {$member->name}",
                'category' => 'bereavement', 'description' => "Burial support for the late {$member->name}.",
                'amount_requested' => $v['welfareAmount'] ?? null, 'recorded_by' => $request->user()->id,
            ]) : null;

            return CareCase::create([
                'type' => 'member_death', 'member_id' => $member->id, 'subject' => $member->name, 'date' => $v['dateOfDeath'],
                'burial_date' => $v['burialDate'] ?? null, 'burial_place' => $v['burialPlace'] ?? null, 'notes' => $v['notes'] ?? null,
                'announcement_id' => $announcement?->id, 'welfare_case_id' => $welfare?->id, 'recorded_by' => $request->user()->id,
            ]);
        });

        $smsSent = false;
        $phone = $member->nextOfKin?->phone ?? $member->next_of_kin_phone;
        if (($v['smsNextOfKin'] ?? false) && $phone) {
            $text = $v['smsMessage'] ?? "Dear {name}, the {church} family is deeply saddened by the passing of {$member->name}. We stand with you in prayer. Psalm 34:18";
            $batch = $sms->send(['type' => 'numbers', 'numbers' => $phone], str_replace('{name}', Str::of($member->nextOfKin?->name ?? $member->next_of_kin_name ?? 'friend')->explode(' ')->first(), $text), $request->user());
            $smsSent = $batch->sent_count > 0;
        }

        return response()->json(['data' => $this->presentCase($case->fresh(['member', 'welfareCase'])) + ['smsSent' => $smsSent]], 201);
    }

    private function caseData(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'type' => [$update ? 'sometimes' : 'required', Rule::in(CareCase::TYPES)],
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'subject' => ['nullable', 'string', 'max:255'],
            'relationship' => ['nullable', 'string', 'max:50'],
            'date' => [$update ? 'sometimes' : 'required', 'date'],
            'burialDate' => ['nullable', 'date'],
            'burialPlace' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'welfareCaseId' => ['nullable', 'integer', 'exists:welfare_cases,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function visitData(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'careCaseId' => ['nullable', 'integer', 'exists:care_cases,id'],
            'memberId' => ['nullable', 'integer', 'exists:members,id'],
            'visitee' => [$update ? 'sometimes' : 'required_without:memberId', 'nullable', 'string', 'max:255'],
            'type' => [$update ? 'sometimes' : 'required', Rule::in(PastoralVisit::TYPES)],
            'date' => [$update ? 'sometimes' : 'required', 'date', \App\Support\ChurchDate::notFuture()],
            'place' => ['nullable', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'giftAmount' => ['nullable', 'numeric', 'min:0'],
            'followUpOn' => ['nullable', 'date'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function presentCase(CareCase $c): array
    {
        return [
            'id' => (string) $c->id, 'type' => $c->type, 'memberId' => $c->member_id ? (string) $c->member_id : null,
            'memberName' => $c->member?->name, 'subject' => $c->subject, 'relationship' => $c->relationship,
            'date' => $c->date->toDateString(), 'burialDate' => $c->burial_date?->toDateString(), 'burialPlace' => $c->burial_place,
            'status' => $c->status, 'welfareStatus' => $c->welfareCase?->status, 'welfareCaseId' => $c->welfare_case_id ? (string) $c->welfare_case_id : null,
            'announcementId' => $c->announcement_id ? (string) $c->announcement_id : null,
            'notes' => $c->notes, 'visits' => (int) ($c->visits_count ?? 0),
        ];
    }

    private function presentVisit(PastoralVisit $v): array
    {
        return [
            'id' => (string) $v->id, 'careCaseId' => $v->care_case_id ? (string) $v->care_case_id : null,
            'memberId' => $v->member_id ? (string) $v->member_id : null, 'visitee' => $v->visiteeLabel(),
            'type' => $v->type, 'date' => $v->date->toDateString(), 'place' => $v->place, 'team' => $v->team,
            'notes' => $v->notes, 'giftAmount' => $v->gift_amount !== null ? (float) $v->gift_amount : null,
            'followUpOn' => $v->follow_up_on?->toDateString(),
        ];
    }
}
