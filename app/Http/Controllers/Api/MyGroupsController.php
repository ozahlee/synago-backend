<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CellGroup;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Position;
use App\Models\SundaySchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "My groups": the classes, ministries and cells the signed-in position-holder leads, with their people. */
class MyGroupsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->member_id) {
            return response()->json(['data' => ['groups' => [], 'linked' => false]]);
        }

        $positions = Position::current()->where('member_id', $user->member_id)->whereNotNull('scope_id')->get();
        $people = fn ($q) => $q->where('status', 'active')->orderBy('name')
            ->get(['id', 'name', 'phone', 'gender', 'date_of_birth', 'pledge_number', 'next_of_kin_name', 'next_of_kin_phone'])
            ->map(fn (Member $m) => [
                'id' => (string) $m->id, 'name' => $m->name, 'phone' => $m->phone, 'age' => $m->date_of_birth?->age,
                'pledgeNumber' => $m->pledge_number, 'parent' => $m->next_of_kin_name, 'parentPhone' => $m->next_of_kin_phone,
            ]);

        $groups = $positions->map(function (Position $p) use ($people) {
            $base = ['positionTitle' => $p->title, 'type' => $p->scope_type, 'id' => (string) $p->scope_id];

            return match ($p->scope_type) {
                'sunday_school_class' => ($c = SundaySchoolClass::find($p->scope_id)) ? $base + [
                    'name' => $c->name, 'subtitle' => "Ages {$c->min_age}–{$c->max_age}".($c->room ? " · {$c->room}" : ''),
                    'people' => $people(Member::where('sunday_school_class_id', $c->id)),
                ] : null,
                'ministry' => ($m = Ministry::find($p->scope_id)) ? $base + [
                    'name' => $m->name, 'subtitle' => collect([$m->meeting_day, $m->meeting_time, $m->location])->filter()->implode(' · '),
                    'people' => $people(Member::whereRelation('ministries', 'ministries.id', $m->id)),
                    'activities' => $m->activities()->orderByDesc('scheduled_at')->limit(5)->get()
                        ->map(fn ($a) => ['id' => (string) $a->id, 'title' => $a->title, 'scheduledAt' => $a->scheduled_at?->toIso8601String()]),
                ] : null,
                'cell_group' => ($g = CellGroup::find($p->scope_id)) ? $base + [
                    'name' => $g->name, 'subtitle' => collect([$g->meeting_day, $g->meeting_time, $g->host])->filter()->implode(' · '),
                    'people' => $people(Member::where('cell_group_id', $g->id)),
                ] : null,
                default => null,
            };
        })->filter()->values();

        return response()->json(['data' => ['groups' => $groups, 'linked' => true]]);
    }
}
