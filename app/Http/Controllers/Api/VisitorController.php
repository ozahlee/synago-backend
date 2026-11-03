<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Visitor;
use App\Models\VisitorFollowUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** First-time visitors and the follow-up that turns them into members. */
class VisitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Visitor::query()->with('assignee')->withCount('followUps')
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('village', 'like', "%{$s}%")))
            ->inPeriod('first_visit', ...$this->period($request))
            ->latest('first_visit')->latest('id')
            ->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($v) => $this->present($v)), 'meta' => $this->meta($rows)]);
    }

    public function stats(): JsonResponse
    {
        $counts = Visitor::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $since = now()->subDays(30)->toDateString();

        return response()->json(['data' => [
            'byStatus' => collect(Visitor::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'last30Days' => Visitor::whereDate('first_visit', '>=', $since)->count(),
            // New for more than 2 days and nobody has called yet
            'needsCall' => Visitor::where('status', 'new')->where('called', false)->whereDate('first_visit', '<=', now()->subDays(2)->toDateString())->count(),
            'joinedThisYear' => Visitor::where('status', 'joined')->whereYear('updated_at', now()->year)->count(),
        ]]);
    }

    public function show(Visitor $visitor): JsonResponse
    {
        $visitor->load(['assignee', 'followUps.user'])->loadCount('followUps');

        return response()->json(['data' => $this->present($visitor) + [
            'followUps' => $visitor->followUps->map(fn (VisitorFollowUp $f) => [
                'id' => (string) $f->id, 'method' => $f->method, 'note' => $f->note,
                'date' => $f->date->toDateString(), 'by' => $f->user?->name,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Visitor::create($this->validated($request));

        return response()->json(['data' => $this->present($v->loadCount('followUps'))], 201);
    }

    public function update(Request $request, Visitor $visitor): JsonResponse
    {
        $visitor->update($this->validated($request, true));

        return response()->json(['data' => $this->present($visitor->fresh('assignee')->loadCount('followUps'))]);
    }

    public function destroy(Visitor $visitor): Response
    {
        $visitor->delete();

        return response()->noContent();
    }

    /** Log a contact; ticks the matching checklist step and moves "new" visitors to "contacted". */
    public function followUp(Request $request, Visitor $visitor): JsonResponse
    {
        $v = $request->validate([
            'method' => ['required', Rule::in(VisitorFollowUp::METHODS)],
            'note' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
        ]);
        $visitor->followUps()->create([...$v, 'user_id' => $request->user()->id]);

        $step = match ($v['method']) { 'call', 'whatsapp' => 'called', 'visit' => 'visited', 'sms' => 'welcome_sent', default => null };
        $visitor->fill(array_filter([$step => $step ? true : null]));
        if ($visitor->status === 'new') {
            $visitor->status = 'contacted';
        }
        $visitor->save();

        return $this->show($visitor)->setStatusCode(201);
    }

    /** Create a member from the visitor and mark them joined. */
    public function convert(Request $request, Visitor $visitor): JsonResponse
    {
        abort_if($visitor->member_id, 422, 'This visitor is already a member.');
        $gender = $request->validate(['gender' => [Rule::requiredIf(! $visitor->gender), Rule::in(['male', 'female'])]])['gender'] ?? $visitor->gender;

        $member = DB::transaction(function () use ($visitor, $gender) {
            $member = Member::create([
                'name' => $visitor->name, 'phone' => $visitor->phone, 'email' => $visitor->email, 'gender' => $gender,
                'village' => $visitor->village, 'date_joined' => today(), 'status' => 'active',
            ]);
            $visitor->update(['status' => 'joined', 'member_id' => $member->id]);

            return $member;
        });

        return response()->json(['data' => ['memberId' => (string) $member->id, 'pledgeNumber' => $member->pledge_number]], 201);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        $v = $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'village' => ['nullable', 'string', 'max:100'],
            'firstVisit' => [$req, 'date', \App\Support\ChurchDate::notFuture()],
            'service' => ['nullable', 'string', 'max:50'],
            'invitedBy' => ['nullable', 'string', 'max:255'],
            'howHeard' => ['nullable', 'string', 'max:50'],
            'prayerRequest' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(Visitor::STATUSES)],
            'welcomeSent' => ['sometimes', 'boolean'],
            'called' => ['sometimes', 'boolean'],
            'visited' => ['sometimes', 'boolean'],
            'invitedBack' => ['sometimes', 'boolean'],
            'assignedTo' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function present(Visitor $v): array
    {
        return [
            'id' => (string) $v->id,
            'name' => $v->name, 'phone' => $v->phone, 'email' => $v->email, 'gender' => $v->gender, 'village' => $v->village,
            'firstVisit' => $v->first_visit->toDateString(), 'service' => $v->service,
            'invitedBy' => $v->invited_by, 'howHeard' => $v->how_heard, 'prayerRequest' => $v->prayer_request,
            'status' => $v->status,
            'welcomeSent' => $v->welcome_sent, 'called' => $v->called, 'visited' => $v->visited, 'invitedBack' => $v->invited_back,
            'assignedTo' => $v->assigned_to ? (string) $v->assigned_to : null,
            'assignee' => $v->relationLoaded('assignee') ? $v->assignee?->name : null,
            'memberId' => $v->member_id ? (string) $v->member_id : null,
            'followUpCount' => (int) ($v->follow_ups_count ?? 0),
            'notes' => $v->notes,
        ];
    }
}
