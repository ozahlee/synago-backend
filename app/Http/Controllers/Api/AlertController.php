<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AlertRequest;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Staff notifications. Note: read/important/archived are stored per alert, so for
 * broadcast alerts (user_id = null) those flags are shared across all staff.
 */
class AlertController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $alerts = Alert::visibleTo($request->user())
            ->where('archived', $request->boolean('archived'))
            ->when($request->has('read'), fn ($q) => $q->where('read', $request->boolean('read')))
            ->when($request->has('important'), fn ($q) => $q->where('important', $request->boolean('important')))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->latest()
            ->paginate($this->perPage($request));

        return AlertResource::collection($alerts);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'count' => Alert::visibleTo($request->user())->where('read', false)->where('archived', false)->count(),
        ]]);
    }

    public function store(AlertRequest $request): AlertResource
    {
        return new AlertResource(Alert::create($request->payload()));
    }

    public function update(Request $request, Alert $alert): AlertResource
    {
        $this->ensureVisible($request, $alert);

        $data = $request->validate([
            'read' => ['sometimes', 'boolean'],
            'important' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ]);
        $alert->update($data);

        return new AlertResource($alert);
    }

    public function markAllRead(Request $request): Response
    {
        Alert::visibleTo($request->user())->where('read', false)->update(['read' => true]);

        return response()->noContent();
    }

    public function destroy(Request $request, Alert $alert): Response
    {
        $this->ensureVisible($request, $alert);
        $alert->delete();

        return response()->noContent();
    }

    private function ensureVisible(Request $request, Alert $alert): void
    {
        abort_unless($alert->user_id === null || $alert->user_id === $request->user()->id, 404);
    }
}
