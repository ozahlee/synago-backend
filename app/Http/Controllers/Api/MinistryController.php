<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MinistryActivityRequest;
use App\Http\Requests\MinistryRequest;
use App\Http\Resources\MinistryResource;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\MinistryActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class MinistryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $ministries = Ministry::query()
            ->with('leader')
            ->withCount('members')
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderBy('name')
            ->get();

        return MinistryResource::collection($ministries);
    }

    public function store(MinistryRequest $request): MinistryResource
    {
        $ministry = Ministry::create($request->payload());

        return $this->detail($ministry);
    }

    public function show(Ministry $ministry): MinistryResource
    {
        return $this->detail($ministry);
    }

    public function update(MinistryRequest $request, Ministry $ministry): MinistryResource
    {
        $ministry->update($request->payload());

        return $this->detail($ministry);
    }

    public function destroy(Ministry $ministry): Response
    {
        $ministry->delete();

        return response()->noContent();
    }

    public function addMember(Request $request, Ministry $ministry): MinistryResource
    {
        $data = $request->validate(['memberId' => ['required', 'integer', 'exists:members,id']]);

        $ministry->members()->syncWithoutDetaching([$data['memberId'] => ['joined_at' => now()->toDateString()]]);

        return $this->detail($ministry);
    }

    public function removeMember(Ministry $ministry, Member $member): MinistryResource
    {
        $ministry->members()->detach($member->id);

        return $this->detail($ministry);
    }

    public function storeActivity(MinistryActivityRequest $request, Ministry $ministry): JsonResponse
    {
        $ministry->activities()->create($request->payload());

        return $this->detail($ministry)->response()->setStatusCode(201);
    }

    public function destroyActivity(Ministry $ministry, MinistryActivity $activity): Response
    {
        abort_unless($activity->ministry_id === $ministry->id, 404);
        $activity->delete();

        return response()->noContent();
    }

    private function detail(Ministry $ministry): MinistryResource
    {
        return new MinistryResource(
            $ministry->load(['leader', 'members', 'activities' => fn ($q) => $q->latest('scheduled_at')])
                ->loadCount('members')
        );
    }
}
