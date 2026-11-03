<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Position;
use App\Models\SundaySchoolAttendance;
use App\Models\SundaySchoolClass;
use App\Support\Leadership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SundaySchoolController extends Controller
{
    public function index(): JsonResponse
    {
        $classes = SundaySchoolClass::withCount(['pupils' => fn ($q) => $q->where('status', 'active')])->orderBy('min_age')->get();
        $teachers = $this->teachers($classes->pluck('id')->all());
        $lastSunday = SundaySchoolAttendance::max('date');

        return response()->json(['data' => [
            'classes' => $classes->map(fn ($c) => $this->present($c, $teachers[$c->id] ?? [], $lastSunday)),
            'unplaced' => Member::active()->where('sunday_school', true)->whereNull('sunday_school_class_id')->count(),
            'enrolled' => Member::active()->where('sunday_school', true)->count(),
            'maxAge' => SundaySchoolClass::MAX_AGE,
        ]]);
    }

    public function show(Request $request, SundaySchoolClass $class): JsonResponse
    {
        Leadership::authorize($request->user(), 'sunday_school_class', $class->id, 'members.view');
        $pupils = $class->pupils()->where('status', 'active')->with('nextOfKin')->orderBy('name')->get();
        $dates = SundaySchoolAttendance::where('sunday_school_class_id', $class->id)->select('date')->distinct()
            ->orderByDesc('date')->limit(8)->pluck('date');
        $present = SundaySchoolAttendance::where('sunday_school_class_id', $class->id)->whereIn('date', $dates)->get()
            ->groupBy('member_id')->map->count();

        return response()->json(['data' => $this->present($class->loadCount('pupils'), $this->teachers([$class->id])[$class->id] ?? []) + [
            'pupilList' => $pupils->map(fn (Member $m) => [
                'id' => (string) $m->id, 'name' => $m->name, 'gender' => $m->gender,
                'age' => $m->date_of_birth?->age, 'dateOfBirth' => $m->date_of_birth?->toDateString(),
                'parent' => $m->nextOfKin?->name ?? $m->next_of_kin_name,
                'parentPhone' => $m->nextOfKin?->phone ?? $m->next_of_kin_phone,
                'attended' => (int) ($present[$m->id] ?? 0),
            ]),
            'recentSundays' => $dates->count(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $c = SundaySchoolClass::create($this->validated($request));

        return response()->json(['data' => $this->present($c->loadCount('pupils'), [])], 201);
    }

    public function update(Request $request, SundaySchoolClass $class): JsonResponse
    {
        $class->update($this->validated($request, $class));

        return response()->json(['data' => $this->present($class->loadCount('pupils'), $this->teachers([$class->id])[$class->id] ?? [])]);
    }

    public function destroy(SundaySchoolClass $class): Response
    {
        $class->delete();

        return response()->noContent();
    }

    /** Who was present on a day: [memberId...]. Teachers of the class may read and record it. */
    public function attendance(Request $request, SundaySchoolClass $class): JsonResponse
    {
        Leadership::authorize($request->user(), 'sunday_school_class', $class->id, 'members.view');
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        return response()->json(['data' => [
            'date' => substr($date, 0, 10),
            'present' => SundaySchoolAttendance::where('sunday_school_class_id', $class->id)->whereDate('date', $date)
                ->pluck('member_id')->map(fn ($id) => (string) $id),
        ]]);
    }

    public function saveAttendance(Request $request, SundaySchoolClass $class): JsonResponse
    {
        Leadership::authorize($request->user(), 'sunday_school_class', $class->id, 'members.manage');
        $v = $request->validate([
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'present' => ['present', 'array'],
            'present.*' => ['integer', Rule::exists('members', 'id')->where('sunday_school_class_id', $class->id)],
        ]);
        DB::transaction(function () use ($class, $v) {
            SundaySchoolAttendance::where('sunday_school_class_id', $class->id)->whereDate('date', $v['date'])->delete();
            foreach (array_unique($v['present']) as $id) {
                SundaySchoolAttendance::create(['sunday_school_class_id' => $class->id, 'member_id' => $id, 'date' => $v['date']]);
            }
        });

        return $this->attendance((new Request(['date' => $v['date']]))->setUserResolver(fn () => $request->user()), $class);
    }

    /**
     * Re-sort everyone by age: children (≤ 12) are enrolled and moved to the class for their age;
     * those who have grown past 12 graduate out of Sunday school.
     */
    public function autoAssign(): JsonResponse
    {
        $placed = $moved = $graduated = 0;
        DB::transaction(function () use (&$placed, &$moved, &$graduated) {
            Member::active()->whereNotNull('date_of_birth')->each(function (Member $m) use (&$placed, &$moved, &$graduated) {
                $age = $m->date_of_birth->age;
                if ($age <= SundaySchoolClass::MAX_AGE) {
                    $class = SundaySchoolClass::forAge($age);
                    if ($class && $m->sunday_school_class_id !== $class->id) {
                        $m->sunday_school_class_id ? $moved++ : $placed++;
                        $m->sunday_school_class_id = $class->id;
                    }
                    $m->sunday_school = true;
                } elseif ($m->sunday_school || $m->sunday_school_class_id) {
                    $m->sunday_school = false;
                    $m->sunday_school_class_id = null;
                    $graduated++;
                }
                $m->saveQuietly();
            });
        });

        return response()->json(['data' => compact('placed', 'moved', 'graduated')]);
    }

    private function teachers(array $classIds): array
    {
        return Position::current()->with('member')->where('scope_type', 'sunday_school_class')->whereIn('scope_id', $classIds)->get()
            ->groupBy('scope_id')
            ->map(fn ($g) => $g->map(fn (Position $p) => ['memberId' => (string) $p->member_id, 'name' => $p->member?->name, 'phone' => $p->member?->phone, 'title' => $p->title])->values()->all())
            ->all();
    }

    private function present(SundaySchoolClass $c, array $teachers, $lastSunday = null): array
    {
        $last = $lastSunday ? SundaySchoolAttendance::where('sunday_school_class_id', $c->id)->whereDate('date', $lastSunday)->count() : null;

        return [
            'id' => (string) $c->id, 'name' => $c->name, 'minAge' => $c->min_age, 'maxAge' => $c->max_age,
            'room' => $c->room, 'description' => $c->description, 'pupils' => (int) ($c->pupils_count ?? 0),
            'teachers' => $teachers, 'lastAttendance' => $last,
        ];
    }

    private function validated(Request $request, ?SundaySchoolClass $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $v = $request->validate([
            'name' => [$req, 'string', 'max:100', Rule::unique('sunday_school_classes', 'name')->ignore($existing)],
            'minAge' => [$req, 'integer', 'min:0', 'max:'.SundaySchoolClass::MAX_AGE],
            'maxAge' => [$req, 'integer', 'min:0', 'max:'.SundaySchoolClass::MAX_AGE, 'gte:minAge'],
            'room' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [\Illuminate\Support\Str::snake($k) => $val])->all();
    }
}
