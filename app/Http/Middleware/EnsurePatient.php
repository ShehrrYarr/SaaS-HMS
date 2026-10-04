<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Patient portal: the user must be linked to a patient record of this hospital. */
class EnsurePatient
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->hospital_id === tenancy()->id(), 403);

        if (! $user->patient) {
            return $user->isPatientOnly()
                ? abort(403, 'No patient record is linked to this account.')
                : redirect()->route('tenant.dashboard');
        }

        abort_unless(hospital()->hasModule('portal'), 403, 'The patient portal is not enabled for this hospital.');

        return $next($request);
    }
}
