<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FBR register: only this hospital's FBR Officer accounts; everyone else goes to their own home. */
class EnsureFbrOfficer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->hospital_id === tenancy()->id(), 403);

        if (! $user->isFbrOfficer()) {
            return redirect($user->homeUrl());
        }

        return $next($request);
    }
}
