<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click sign-in to the public demo hospital (no password), per role.
 */
class DemoController extends Controller
{
    public function login(Request $request, string $role)
    {
        abort_unless(config('hms.demo.enabled'), 404);
        $account = config("hms.demo.accounts.{$role}");
        abort_unless($account, 404);

        $hospital = Hospital::where('slug', config('hms.demo.hospital'))->first();
        abort_unless($hospital && ! $hospital->isSuspended(), 503, 'The demo hospital is being reset. Please try again in a minute.');

        $user = User::where('hospital_id', $hospital->id)->where('email', $account['email'])->where('status', 'active')->first();
        abort_unless($user, 503, 'The demo is being prepared. Please try again in a minute.');

        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('demo_role', $role);

        // Roles are team-scoped, so resolve the home URL inside the demo hospital's context.
        return tenancy()->run($hospital, function () use ($user, $role, $account, $request) {
            AuditLog::record('demo_login', $user, [], ['role' => $role, 'ip' => $request->ip()], "Demo sign-in as {$account['label']}");

            return redirect($user->homeUrl());
        });
    }
}
