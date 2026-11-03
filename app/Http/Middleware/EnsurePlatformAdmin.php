<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform-owner console: only platform admins, and never inside a church context. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) ($request->user()?->getAttributes()['is_platform_admin'] ?? false), 403, 'Platform administrators only.');
        Tenant::set(null);

        return $next($request);
    }
}
