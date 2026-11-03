<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CellGroup;
use App\Models\Contribution;
use App\Models\Household;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Transaction;
use App\Services\ContributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Church projects (land, building, borehole…): budget, giving (contributions from members, families, cells,
 * ministries, organisations, well-wishers; cash, pledges, thanksgiving, in kind) and spending.
 */
class ProjectController extends Controller
{
    public function __construct(private ContributionService $contributions) {}

    public function index(Request $request): JsonResponse
    {
        $projects = Project::with(['manager'])
            ->when($request->boolean('open'), fn ($q) => $q->where('open_for_contributions', true))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'planning' THEN 1 WHEN 'on_hold' THEN 2 ELSE 3 END")->orderBy('name')->get();

        return response()->json(['data' => $projects->map(fn ($p) => $this->present($p))]);
    }

    public function stats(): JsonResponse
    {
        $open = Project::where('open_for_contributions', true)->get();
        $year = now()->year;

        return response()->json(['data' => [
            'openProjects' => $open->count(),
            'raisedThisYear' => round((float) Contribution::whereYear('date', $year)->sum('amount'), 2),
            'inKindThisYear' => round((float) Contribution::whereYear('date', $year)->where('kind', 'in_kind')->sum('in_kind_value'), 2),
            'contributors' => Contribution::whereYear('date', $year)->get(['contributor_type', 'member_id', 'household_id', 'cell_group_id', 'ministry_id', 'organisation_id', 'contributor_name'])
                ->map(fn ($c) => $c->contributor_type.':'.($c->member_id ?? $c->household_id ?? $c->cell_group_id ?? $c->ministry_id ?? $c->organisation_id ?? strtolower((string) $c->contributor_name)))
                ->reject(fn ($k) => str_starts_with($k, 'anonymous'))->unique()->count(),
            'goalOpen' => round($open->sum(fn ($p) => $p->goal()), 2),
            'raisedOpen' => round($open->sum(fn ($p) => $p->raised()), 2),
        ]]);
    }

    public function show(Project $project): JsonResponse
    {
        $project->load(['manager', 'milestones']);
        $tx = $project->transactions()->latest('date')->latest('id')->get();
        $targets = DB::table('project_cell_targets')->where('project_id', $project->id)->pluck('target', 'cell_group_id');

        return response()->json(['data' => $this->present($project) + [
            'transactions' => $tx->map(fn (Transaction $t) => [
                'id' => (string) $t->id, 'date' => $t->date->toDateString(), 'type' => $t->type, 'category' => $t->category,
                'amount' => (float) $t->amount, 'description' => $t->description, 'payee' => $t->payee, 'reference' => $t->reference,
            ]),
            'byCategory' => $tx->where('type', 'expense')->groupBy('category')->map(fn ($g, $c) => ['category' => $c, 'amount' => round((float) $g->sum('amount'), 2)])->values(),
            'milestones' => $project->milestones->map(fn ($m) => $this->presentMilestone($m)),
            'events' => \App\Models\Event::where('project_id', $project->id)->orderByDesc('starts_at')->get()
                ->map(fn ($e) => ['id' => (string) $e->id, 'title' => $e->title, 'date' => $e->starts_at->toDateString()]),
            'cellTargets' => $targets->map(fn ($t, $cell) => ['cellGroupId' => (string) $cell, 'target' => (float) $t])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = Project::create($this->validated($request));

        return response()->json(['data' => $this->present($p)], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $project->update($this->validated($request, true));

        return response()->json(['data' => $this->present($project->fresh(['manager']))]);
    }

    public function destroy(Project $project): Response
    {
        abort_if($project->contributions()->exists(), 422, 'This project has contributions. Close it to giving instead of deleting it.');
        $project->delete(); // its transactions stay in Accounts, untagged

        return response()->noContent();
    }

    /** Record spending (or income) straight against the project: creates a tagged Accounts transaction. */
    public function addTransaction(Request $request, Project $project): JsonResponse
    {
        $v = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
        ]);
        Transaction::create([...collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all(), 'project_id' => $project->id, 'recorded_by' => $request->user()->id]);

        return $this->show($project)->setStatusCode(201);
    }

    // ---------------------------------------------------------------- contributions

    public function contributions(Request $request, Project $project): JsonResponse
    {
        $rows = $project->contributions()->with(Contribution::RELATIONS)
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('contributorType'), fn ($q, $v) => $q->where('contributor_type', $v))
            ->when($request->query('eventId'), fn ($q, $v) => $q->where('event_id', $v))
            ->inPeriod('date', ...$this->period($request))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('reference', 'like', "%{$s}%")
                ->orWhere('contributor_name', 'like', "%{$s}%")->orWhere('in_kind_description', 'like', "%{$s}%")
                ->orWhereHas('member', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('pledge_number', 'like', "%{$s}%"))
                ->orWhereHas('household', fn ($q) => $q->where('name', 'like', "%{$s}%"))
                ->orWhereHas('organisation', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->latest('date')->latest('id')->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($c) => $this->presentContribution($c)), 'meta' => $this->meta($rows)]);
    }

    public function storeContribution(Request $request, Project $project): JsonResponse
    {
        $v = $request->validate([
            'kind' => ['required', Rule::in(Contribution::KINDS)],
            'contributorType' => [Rule::requiredIf($request->input('kind') !== 'pledge_payment'), Rule::in(Contribution::CONTRIBUTORS)],
            'memberId' => ['required_if:contributorType,member', 'nullable', 'integer', 'exists:members,id'],
            'householdId' => ['required_if:contributorType,household', 'nullable', 'integer', 'exists:households,id'],
            'cellGroupId' => ['required_if:contributorType,cell_group', 'nullable', 'integer', 'exists:cell_groups,id'],
            'ministryId' => ['required_if:contributorType,ministry', 'nullable', 'integer', 'exists:ministries,id'],
            'organisationId' => ['required_if:contributorType,organisation', 'nullable', 'integer', 'exists:organisations,id'],
            'contributorName' => ['required_if:contributorType,named', 'nullable', 'string', 'max:255'],
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'amount' => [Rule::requiredIf($request->input('kind') !== 'in_kind'), 'nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'inKindDescription' => ['required_if:kind,in_kind', 'nullable', 'string', 'max:255'],
            'inKindValue' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'paymentMethod' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:50', 'unique:contributions,reference', 'unique:transactions,reference', 'unique:offertories,reference'],
            'service' => ['nullable', 'string', 'max:50'],
            'eventId' => ['nullable', 'integer', 'exists:events,id'],
            'pledgeId' => ['required_if:kind,pledge_payment', 'nullable', 'integer', 'exists:pledges,id'],
            'hideName' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            'sendThanks' => ['sometimes', 'boolean'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        abort_unless($project->open_for_contributions || $project->status !== 'completed', 422, 'This project is closed to contributions.');

        $data = collect($v)->except(['sendThanks', 'phone'])->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
        // keep only the id that matches the contributor type
        foreach (['member' => 'member_id', 'household' => 'household_id', 'cell_group' => 'cell_group_id', 'ministry' => 'ministry_id', 'organisation' => 'organisation_id'] as $type => $col) {
            if (($data['contributor_type'] ?? null) !== $type) {
                unset($data[$col]);
            }
        }
        if (($data['contributor_type'] ?? null) !== 'named') {
            unset($data['contributor_name']);
        }

        $c = $this->contributions->record($project, $data, $request->user(), (bool) ($v['sendThanks'] ?? false), $v['phone'] ?? null);

        return response()->json(['data' => $this->presentContribution($c->fresh(Contribution::RELATIONS))], 201);
    }

    public function updateContribution(Request $request, Contribution $contribution): JsonResponse
    {
        $v = $request->validate([
            'hideName' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            'inKindDescription' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inKindValue' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        $contribution->update(collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all());

        return response()->json(['data' => $this->presentContribution($contribution->fresh(Contribution::RELATIONS))]);
    }

    public function destroyContribution(Contribution $contribution): Response
    {
        $this->contributions->delete($contribution);

        return response()->noContent();
    }

    public function thank(Request $request, Contribution $contribution): JsonResponse
    {
        $sent = $this->contributions->thank($contribution->load(Contribution::RELATIONS), $contribution->project, $request->user(), $request->input('phone'));
        abort_unless($sent, 422, 'No phone number on record for this contributor.');

        return response()->json(['data' => ['sent' => true]]);
    }

    /** Honour roll, cell league with targets, family progress and the breakdowns. */
    public function summary(Project $project): JsonResponse
    {
        $all = $project->contributions()->with(Contribution::RELATIONS)->get();
        $value = fn (Contribution $c) => (float) $c->amount + (float) ($c->in_kind_value ?? 0);

        // honour roll: by contributor, names hidden on request
        $roll = $all->groupBy(fn ($c) => $c->contributor_type.':'.($c->member_id ?? $c->household_id ?? $c->cell_group_id ?? $c->ministry_id ?? $c->organisation_id ?? strtolower((string) $c->contributor_name)))
            ->reject(fn ($g, $k) => str_starts_with($k, 'anonymous'))
            ->map(fn ($g) => [
                'name' => $g->contains('hide_name', true) ? 'Well-wisher' : $g->first()->contributorLabel(),
                'type' => $g->first()->contributor_type, 'total' => round($g->sum($value), 2), 'gifts' => $g->count(),
            ])->sortByDesc('total')->values()->take(50);

        // cell league
        $targets = DB::table('project_cell_targets')->where('project_id', $project->id)->pluck('target', 'cell_group_id');
        $byCell = $all->groupBy(fn ($c) => $c->cellGroupId() ?? 'none');
        $cells = CellGroup::with('area')->get()->map(fn ($g) => [
            'id' => (string) $g->id, 'name' => $g->name, 'area' => $g->area?->name,
            'total' => round($byCell->get($g->id, collect())->sum($value), 2),
            'target' => isset($targets[$g->id]) ? (float) $targets[$g->id] : null,
        ])->sortByDesc('total')->values();

        // families vs the suggested amount: a family's own gifts plus those of its members
        $families = Household::with('head')->withCount('members')->get()->map(function (Household $h) use ($all, $value, $project) {
            $memberIds = $h->members()->pluck('id');
            $total = $all->filter(fn ($c) => $c->household_id === $h->id || ($c->member_id && $memberIds->contains($c->member_id)))->sum($value);

            return ['id' => (string) $h->id, 'name' => $h->name, 'head' => $h->head?->name, 'members' => $h->members_count,
                'total' => round($total, 2), 'suggested' => $project->suggested_family_amount !== null ? (float) $project->suggested_family_amount : null];
        })->sortByDesc('total')->values();

        $events = $all->whereNotNull('event_id')->groupBy('event_id')->map(fn ($g) => [
            'id' => (string) $g->first()->event_id, 'title' => $g->first()->event?->title, 'date' => $g->first()->event?->starts_at?->toDateString(),
            'total' => round($g->sum($value), 2), 'gifts' => $g->count(),
        ])->values();

        $monthly = $all->groupBy(fn ($c) => $c->date->format('Y-m'))->sortKeys()->map(fn ($g, $m) => ['month' => $m, 'total' => round($g->sum('amount'), 2)])->values();

        return response()->json(['data' => [
            'byKind' => collect(Contribution::KINDS)->mapWithKeys(fn ($k) => [$k => round($all->where('kind', $k)->sum($value), 2)]),
            'byContributorType' => collect(Contribution::CONTRIBUTORS)->mapWithKeys(fn ($k) => [$k => round($all->where('contributor_type', $k)->sum($value), 2)]),
            'honourRoll' => $roll, 'cells' => $cells, 'families' => $families, 'events' => $events, 'monthly' => $monthly,
            'unattributedToCell' => round($byCell->get('none', collect())->sum($value), 2),
        ]]);
    }

    public function setTargets(Request $request, Project $project): JsonResponse
    {
        $v = $request->validate(['cells' => ['present', 'array'], 'cells.*.cellGroupId' => ['required', 'integer', 'exists:cell_groups,id'], 'cells.*.target' => ['nullable', 'numeric', 'min:0']]);
        DB::transaction(function () use ($project, $v) {
            DB::table('project_cell_targets')->where('project_id', $project->id)->delete();
            foreach ($v['cells'] as $c) {
                if (($c['target'] ?? 0) > 0) {
                    DB::table('project_cell_targets')->insert(['project_id' => $project->id, 'cell_group_id' => $c['cellGroupId'], 'target' => $c['target']]);
                }
            }
        });

        return $this->summary($project);
    }

    // ---------------------------------------------------------------- milestones

    public function storeMilestone(Request $request, Project $project): JsonResponse
    {
        $m = $project->milestones()->create($this->milestoneData($request) + ['position' => $project->milestones()->max('position') + 1]);

        return response()->json(['data' => $this->presentMilestone($m)], 201);
    }

    public function updateMilestone(Request $request, ProjectMilestone $milestone): JsonResponse
    {
        $data = $this->milestoneData($request, true);
        if (($data['status'] ?? null) === 'done' && ! $milestone->done_on && empty($data['done_on'])) {
            $data['done_on'] = today();
        }
        if (($data['status'] ?? null) === 'pending') {
            $data['done_on'] = null;
        }
        $milestone->update($data);

        return response()->json(['data' => $this->presentMilestone($milestone)]);
    }

    public function destroyMilestone(ProjectMilestone $milestone): Response
    {
        $milestone->delete();

        return response()->noContent();
    }

    private function milestoneData(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'title' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'dueDate' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['pending', 'done'])],
            'doneOn' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    // ---------------------------------------------------------------- presenters

    private function validated(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'budget' => [$update ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:999999999999'],
            'target' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'status' => ['sometimes', Rule::in(Project::STATUSES)],
            'openForContributions' => ['sometimes', 'boolean'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'deadline' => ['nullable', 'date'],
            'suggestedFamilyAmount' => ['nullable', 'numeric', 'min:0'],
            'managerMemberId' => ['nullable', 'integer', 'exists:members,id'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function present(Project $p): array
    {
        $spent = $p->spent();
        $raised = $p->raised();
        $pledges = $p->pledges()->withSum('payments', 'amount')->get(['id', 'amount']);
        $pledged = (float) $pledges->sum('amount');
        // what is still promised but unpaid (paid pledges already count in "raised")
        $pledgeBalance = (float) $pledges->sum(fn ($x) => max(0, (float) $x->amount - (float) $x->payments_sum_amount));

        return [
            'id' => (string) $p->id, 'name' => $p->name, 'description' => $p->description, 'status' => $p->status,
            'openForContributions' => $p->open_for_contributions,
            'budget' => (float) $p->budget, 'target' => $p->target !== null ? (float) $p->target : null, 'goal' => $p->goal(),
            'raised' => round($raised, 2), 'inKindValue' => round($p->inKindValue(), 2), 'pledged' => round($pledged, 2), 'pledgeBalance' => round($pledgeBalance, 2),
            'spent' => round($spent, 2), 'remaining' => round((float) $p->budget - $spent, 2),
            'contributions' => $p->contributions()->count(),
            'startDate' => $p->start_date?->toDateString(), 'endDate' => $p->end_date?->toDateString(), 'deadline' => $p->deadline?->toDateString(),
            'suggestedFamilyAmount' => $p->suggested_family_amount !== null ? (float) $p->suggested_family_amount : null,
            'managerMemberId' => $p->manager_member_id ? (string) $p->manager_member_id : null, 'manager' => $p->manager?->name,
        ];
    }

    private function presentContribution(Contribution $c): array
    {
        return [
            'id' => (string) $c->id, 'projectId' => (string) $c->project_id, 'date' => $c->date->toDateString(), 'kind' => $c->kind,
            'contributorType' => $c->contributor_type, 'contributor' => $c->contributorLabel(),
            'memberId' => $c->member_id ? (string) $c->member_id : null, 'pledgeNumber' => $c->member?->pledge_number,
            'householdId' => $c->household_id ? (string) $c->household_id : null, 'cellGroupId' => $c->cell_group_id ? (string) $c->cell_group_id : null,
            'organisationId' => $c->organisation_id ? (string) $c->organisation_id : null,
            'amount' => (float) $c->amount, 'inKindDescription' => $c->in_kind_description,
            'inKindValue' => $c->in_kind_value !== null ? (float) $c->in_kind_value : null,
            'paymentMethod' => $c->payment_method, 'reference' => $c->reference, 'service' => $c->service,
            'eventId' => $c->event_id ? (string) $c->event_id : null, 'event' => $c->event?->title,
            'pledgeId' => $c->pledge_id ? (string) $c->pledge_id : null, 'hideName' => $c->hide_name, 'notes' => $c->notes,
            'canThank' => (bool) $c->contactPhone(),
        ];
    }

    private function presentMilestone(ProjectMilestone $m): array
    {
        return ['id' => (string) $m->id, 'title' => $m->title, 'amount' => $m->amount !== null ? (float) $m->amount : null,
            'dueDate' => $m->due_date?->toDateString(), 'status' => $m->status, 'doneOn' => $m->done_on?->toDateString(), 'notes' => $m->notes];
    }
}
