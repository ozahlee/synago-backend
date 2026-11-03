<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $members = Member::query()
            ->with(['ministries', 'sundaySchoolClass', 'cellGroup', 'nextOfKin'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('pledge_number', 'like', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('gender'), fn ($q, $v) => $q->where('gender', $v))
            ->when($request->has('sundaySchool'), fn ($q) => $q->where('sunday_school', $request->boolean('sundaySchool')))
            ->when($request->query('ministryId'), fn ($q, $id) => $q->whereRelation('ministries', 'ministries.id', $id))
            ->when($request->query('sundaySchoolClassId'), fn ($q, $id) => $q->where('sunday_school_class_id', $id))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return MemberResource::collection($members);
    }

    public function store(MemberRequest $request): MemberResource
    {
        $member = DB::transaction(function () use ($request) {
            $member = Member::create($request->payload(['ministryIds', 'primaryMinistryId']));
            $this->syncMinistries($member, $request);

            return $member;
        });

        return new MemberResource($member->load('ministries'));
    }

    public function show(Member $member): MemberResource
    {
        return new MemberResource($member->load(['ministries', 'positions', 'sundaySchoolClass', 'cellGroup', 'nextOfKin']));
    }

    public function update(MemberRequest $request, Member $member): MemberResource
    {
        DB::transaction(function () use ($request, $member) {
            $member->update($request->payload(['ministryIds', 'primaryMinistryId']));
            $this->syncMinistries($member, $request);
        });

        return new MemberResource($member->load('ministries'));
    }

    public function destroy(Member $member): Response
    {
        $member->delete(); // soft delete: keeps giving history intact

        return response()->noContent();
    }

    private function syncMinistries(Member $member, MemberRequest $request): void
    {
        if (! $request->has('ministryIds')) {
            return;
        }

        $ids = collect($request->validated('ministryIds'));
        $primary = $request->validated('primaryMinistryId') ?? $ids->first();

        $member->ministries()->sync(
            $ids->mapWithKeys(fn ($id) => [$id => ['is_primary' => (int) $id === (int) $primary]])->all()
        );
    }
}
