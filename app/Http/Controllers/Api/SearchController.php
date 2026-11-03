<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Baptism;
use App\Models\CellGroup;
use App\Models\Household;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Marriage;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Pledge;
use App\Models\User;
use App\Models\Visitor;
use App\Models\WelfareCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One search box across modules. Each group is only searched when the user may see that module,
 * so results never reveal records from areas their role can't open.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $limit = max(1, min(10, $request->integer('limit', 5)));
        $user = $request->user();
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $can = fn (string $p) => $user->can($p);

        $groups = [];

        if ($can('members.view')) {
            $groups['members'] = Member::query()->with('ministries')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('pledge_number', 'like', $like)
                    ->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
                // exact pledge number first, then names starting with the query
                ->orderByRaw('CASE WHEN pledge_number = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$q, "{$q}%"])
                ->orderBy('name')->limit($limit)->get()
                ->map(fn (Member $m) => $this->hit('member', $m->id, $m->name,
                    collect([$m->pledge_number, $m->primaryMinistry()?->name, $m->village, $m->status === 'inactive' ? 'inactive' : null])->filter()->implode(' · ')));

            $groups['visitors'] = Visitor::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->latest('first_visit')->limit($limit)->get()
                ->map(fn (Visitor $v) => $this->hit('visitor', $v->id, $v->name,
                    'Visitor · first came '.$v->first_visit->format('j M Y').' · '.$v->status, $v->status));

            $groups['ministries'] = Ministry::where('name', 'like', $like)->withCount('members')->limit($limit)->get()
                ->map(fn (Ministry $m) => $this->hit('ministry', $m->id, $m->name, "{$m->members_count} members"));

            $groups['cellGroups'] = CellGroup::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('village', 'like', $like))
                ->withCount('members')->limit($limit)->get()
                ->map(fn (CellGroup $g) => $this->hit('cellGroup', $g->id, $g->name, "Cell group · {$g->members_count} members"));

            $groups['households'] = Household::with('head')->withCount('members')
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhereHas('head', fn ($h) => $h->where('name', 'like', $like)))
                ->orderBy('name')->limit($limit)->get()
                ->map(fn (Household $h) => $this->hit('household', $h->id, $h->name,
                    collect(['Family', $h->head?->name ? 'head: '.$h->head->name : null, "{$h->members_count} members"])->filter()->implode(' · ')));

            $groups['baptisms'] = Baptism::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('certificate_number', 'like', $like))
                ->latest('date')->limit($limit)->get()
                ->map(fn (Baptism $b) => $this->hit('baptism', $b->id, $b->name,
                    ucfirst($b->kind).' · '.($b->certificate_number ?? 'candidate').' · '.$b->date->format('j M Y'), $b->status));

            $groups['marriages'] = Marriage::where(fn ($w) => $w->where('groom_name', 'like', $like)
                ->orWhere('bride_name', 'like', $like)->orWhere('certificate_number', 'like', $like))
                ->latest('date')->limit($limit)->get()
                ->map(fn (Marriage $m) => $this->hit('marriage', $m->id, "{$m->groom_name} & {$m->bride_name}",
                    'Marriage · '.($m->certificate_number ?? 'planned').' · '.$m->date->format('j M Y'), $m->status));
        }

        if ($can('finance.view')) {
            $groups['pledges'] = Pledge::with('member')->withSum('payments', 'amount')
                ->where(fn ($w) => $w->where('purpose', 'like', $like)->orWhere('pledger_name', 'like', $like)
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like)->orWhere('pledge_number', 'like', $like)))
                ->latest('pledge_date')->limit($limit)->get()
                ->map(fn (Pledge $p) => $this->hit('pledge', $p->id, $p->pledgerLabel(),
                    "{$p->purpose} · ".number_format($p->paid()).' of '.number_format((float) $p->amount).' paid'));
        }

        if ($can('finance.view')) {
            $groups['projects'] = Project::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('description', 'like', $like))
                ->latest()->limit($limit)->get()
                ->map(fn (Project $p) => $this->hit('project', $p->id, $p->name,
                    'Project · '.str_replace('_', ' ', $p->status).($p->open_for_contributions ? ' · open for giving' : ''), $p->status));

            $groups['organisations'] = Organisation::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('contact_name', 'like', $like))
                ->orderBy('name')->limit($limit)->get()
                ->map(fn (Organisation $o) => $this->hit('organisation', $o->id, $o->name, 'Organisation · '.$o->type));
        }

        if ($can('welfare.manage')) {
            $groups['welfare'] = WelfareCase::where('beneficiary', 'like', $like)->latest()->limit($limit)->get()
                ->map(fn (WelfareCase $c) => $this->hit('welfare', $c->id, $c->beneficiary,
                    'Welfare · '.str_replace('_', ' ', $c->category).' · '.$c->status));
        }

        $groups['announcements'] = Announcement::where(fn ($w) => $w->where('title', 'like', $like)
            ->orWhere('subject', 'like', $like)->orWhere('venue', 'like', $like))
            ->latest('event_at')->limit($limit)->get()
            ->map(fn (Announcement $a) => $this->hit('announcement', $a->id, $a->title,
                collect([ucfirst($a->type), $a->subject, $a->event_at?->format('j M Y')])->filter()->implode(' · '),
                $a->event_at && $a->event_at->lt(today()) ? 'past' : 'upcoming'));

        if ($can('users.manage')) {
            $groups['staff'] = User::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->limit($limit)->get()
                ->map(fn (User $u) => $this->hit('staff', $u->id, $u->name, 'Staff · '.$u->getRoleNames()->implode(', ')));
        }

        return response()->json(['data' => collect($groups)->filter(fn ($g) => $g->isNotEmpty())->map->values()]);
    }

    private function hit(string $type, int $id, string $title, ?string $subtitle, ?string $status = null): array
    {
        return ['type' => $type, 'id' => (string) $id, 'title' => $title, 'subtitle' => $subtitle ?: null, 'status' => $status];
    }
}
