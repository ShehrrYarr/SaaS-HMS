<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global: every request starts without a tenant, so no hospital context can
 * leak between requests in long-running processes (queue workers, Octane, tests).
 */
class ResetTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        tenancy()->reset();

        return $next($request);
    }
}
