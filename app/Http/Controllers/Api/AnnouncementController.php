<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Community announcements: burials, weddings, introductions, meetings… */
class AnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $when = $request->query('when', 'upcoming');

        $rows = Announcement::query()
            ->with('member')
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$s}%")->orWhere('subject', 'like', "%{$s}%")->orWhere('venue', 'like', "%{$s}%")))
            ->when($when === 'upcoming', fn ($q) => $q->upcoming()->orderByDesc('pinned')->orderByRaw('event_at is null')->orderBy('event_at'))
            ->when($when === 'past', fn ($q) => $q->past()->orderByDesc('event_at'))
            ->when($when === 'all', fn ($q) => $q->orderByDesc('event_at'))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return AnnouncementResource::collection($rows);
    }

    public function store(AnnouncementRequest $request): AnnouncementResource
    {
        $a = Announcement::create([...$request->payload(), 'created_by' => $request->user()->id]);

        return new AnnouncementResource($a->load('member'));
    }

    public function show(Announcement $announcement): AnnouncementResource
    {
        return new AnnouncementResource($announcement->load('member'));
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): AnnouncementResource
    {
        $announcement->update($request->payload());

        return new AnnouncementResource($announcement->load('member'));
    }

    public function destroy(Announcement $announcement): Response
    {
        $announcement->delete();

        return response()->noContent();
    }
}
