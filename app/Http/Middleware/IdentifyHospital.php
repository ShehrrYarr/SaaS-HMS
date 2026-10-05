<?php

namespace App\Http\Middleware;

use App\Models\Hospital;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {hospital} slug from /h/{hospital}/..., activates the tenant
 * context and makes sure the logged-in user belongs to that hospital.
 * Registered as Livewire persistent middleware so component updates are
 * scoped exactly like the original page request.
 */
class IdentifyHospital
{
    /** Routes reachable while the hospital is suspended. */
    protected array $allowedWhenSuspended = [
        'tenant.login', 'tenant.logout', 'tenant.subscription', 'portal.login',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route?->parameter('hospital');

        $hospital = $slug instanceof Hospital
            ? $slug
            : Hospital::with('plan')->where('slug', $slug)->first();

        abort_unless($hospital, 404, 'Hospital not found.');

        tenancy()->set($hospital);
        URL::defaults(['hospital' => $hospital->slug]);
        $route->forgetParameter('hospital');

        if ($hospital->timezone) {
            config(['app.timezone' => $hospital->timezone]);
            date_default_timezone_set($hospital->timezone);
        }

        View::share('currentHospital', $hospital);

        $user = Auth::user();
        if ($user && $user->hospital_id !== $hospital->id && ! $request->routeIs('tenant.logout', 'portal.logout')) {
            // Signed in to another hospital (or the platform): never show this tenant, but don't
            // sign the account out by surprise either – offer "go back" or "sign out and continue".
            return response()->view('errors.signed-in-elsewhere', ['hospital' => $hospital, 'user' => $user], 403);
        }

        if ($user && ! $user->isActive()) {
            Auth::logout();

            return redirect()->route('tenant.login')->with('error', 'Your account is inactive.');
        }

        if ($hospital->isSuspended() && ! $request->routeIs(...$this->allowedWhenSuspended) && ! $request->routeIs('livewire.*')) {
            return response()->view('errors.suspended', ['hospital' => $hospital], 403);
        }

        return $next($request);
    }
}
