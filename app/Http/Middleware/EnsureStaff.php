<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hospital staff area: patients are sent to their portal. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hospital_id === null) {
            abort(403);
        }

        if ($user->isPatientOnly()) {
            return redirect()->route('portal.dashboard');
        }

        return $next($request);
    }
}
