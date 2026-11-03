<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BaptismRequest;
use App\Http\Resources\BaptismResource;
use App\Models\Baptism;
use App\Models\Member;
use App\Services\SacramentRequirements;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Baptism / confirmation / dedication register. */
class BaptismController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = Baptism::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('certificate_number', 'like', "%{$s}%")
                ->orWhere('father_name', 'like', "%{$s}%")
                ->orWhere('mother_name', 'like', "%{$s}%")))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->when($request->query('year'), fn ($q, $y) => $q->whereDateBetween('date', ["{$y}-01-01", "{$y}-12-31"]))
            ->inPeriod('date', ...$this->period($request))
            // scheduled: soonest first; completed / mixed: most recent first
            ->when($request->query('status') === 'scheduled', fn ($q) => $q->orderBy('date'), fn ($q) => $q->orderByDesc('date'))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return BaptismResource::collection($rows);
    }

    public function stats(): JsonResponse
    {
        $year = now()->year;
        $done = Baptism::where('status', 'completed')
            ->whereDateBetween('date', ["{$year}-01-01", "{$year}-12-31"])
            ->selectRaw('kind, COUNT(*) as c')->groupBy('kind')->pluck('c', 'kind');

        $upcoming = Baptism::where('status', 'scheduled');
        $next = (clone $upcoming)->whereDate('date', '>=', today())->orderBy('date')->value('date');

        return response()->json(['data' => [
            'year' => $year,
            'completedThisYear' => collect(Baptism::KINDS)->mapWithKeys(fn ($k) => [$k => (int) ($done[$k] ?? 0)]),
            'candidates' => (clone $upcoming)->count(),
            'awaitingClass' => (clone $upcoming)->where('class_completed', false)->count(),
            'nextDate' => $next ? substr((string) $next, 0, 10) : null,
            'totalRegistered' => Baptism::where('status', 'completed')->count(),
        ]]);
    }

    public function store(BaptismRequest $request): BaptismResource
    {
        $data = $this->withMemberDefaults($request->payload(['overrideReason']));
        if (($data['status'] ?? 'scheduled') === 'completed') {
            // Entering a past baptism straight into the register: requirements are historical, skip the check.
            $data['override_reason'] = $request->validated('overrideReason') ?? 'Recorded after the event';
        }
        $baptism = Baptism::create([...$data, 'recorded_by' => $request->user()->id]);

        return new BaptismResource($baptism);
    }

    public function show(Baptism $baptism): BaptismResource
    {
        return new BaptismResource($baptism);
    }

    public function update(BaptismRequest $request, Baptism $baptism): BaptismResource
    {
        $data = $this->withMemberDefaults($request->payload(['overrideReason']));
        $this->guardCompletion($request, $baptism, $data);
        $baptism->update($data);

        return new BaptismResource($baptism->fresh());
    }

    public function destroy(Baptism $baptism): Response
    {
        $baptism->delete();

        return response()->noContent();
    }

    /** Tick / untick one requirement on the candidate's checklist. */
    public function requirement(Request $request, Baptism $baptism, string $key, SacramentRequirements $reqs): BaptismResource
    {
        $v = $request->validate([
            'done' => ['required', 'boolean'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
        ]);
        $reqs->mark($baptism, $key, $v['done'], $v['amount'] ?? null, $v['note'] ?? null, $request->user(), $v['paymentMethod'] ?? null);

        return new BaptismResource($baptism->fresh());
    }

    /**
     * Moving a candidate to "completed" needs every requirement met, unless someone allowed to
     * override (admin / pastor) gives a reason.
     */
    private function guardCompletion(Request $request, Baptism $baptism, array &$data): void
    {
        if (($data['status'] ?? null) !== 'completed' || $baptism->status === 'completed') {
            return;
        }
        $probe = $baptism->replicate()->fill($data);
        $missing = app(SacramentRequirements::class)->missing($probe);
        if (! $missing) {
            return;
        }
        $reason = $request->validated('overrideReason');
        if ($reason && $request->user()->can('sacraments.override')) {
            $data['override_reason'] = $reason;

            return;
        }
        throw ValidationException::withMessages([
            'requirements' => 'Still outstanding: '.implode(', ', $missing).'.'
                .($request->user()->can('sacraments.override') ? ' Give a reason to override.' : ''),
        ]);
    }

    /** When linked to a member, fill blank name / gender / DOB from the member record. */
    private function withMemberDefaults(array $data): array
    {
        if (array_key_exists('name', $data) && $data['name'] === null) {
            unset($data['name']); // never blank out an existing name
        }
        if (empty($data['member_id'])) {
            return $data;
        }
        $member = Member::find($data['member_id']);

        return [
            ...$data,
            'name' => $data['name'] ?? $member->name,
            'gender' => $data['gender'] ?? $member->gender,
            'date_of_birth' => $data['date_of_birth'] ?? $member->date_of_birth,
        ];
    }
}
