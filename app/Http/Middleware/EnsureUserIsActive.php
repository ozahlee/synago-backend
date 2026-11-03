<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rejects requests from deactivated staff accounts even if they still hold a token. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        return $next($request);
    }
}
