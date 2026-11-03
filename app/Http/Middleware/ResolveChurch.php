<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every church route: work inside the signed-in user's church; refuse suspended churches. */
class ResolveChurch
{
    public function handle(Request $request, Closure $next): Response
    {
        $churchId = $request->user()?->getAttributes()['church_id'] ?? null;
        if (! $churchId) {
            return response()->json(['message' => 'This account is not linked to a church.'], 403);
        }
        Tenant::set((int) $churchId);
        $church = Tenant::church();
        if (! $church || $church->isSuspended()) {
            return response()->json(['message' => 'This church account is suspended. Please contact Synago support.', 'code' => 'church_suspended'], 403);
        }
        // light activity tracking for the platform console (at most once an hour)
        if (! $church->last_active_at || $church->last_active_at->lt(now()->subHour())) {
            $church->forceFill(['last_active_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
