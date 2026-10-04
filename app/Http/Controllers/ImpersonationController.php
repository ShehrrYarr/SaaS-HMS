<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a Super Admin open a hospital as its Hospital Admin (support use-case)
 * and return to the platform console afterwards.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, Hospital $hospital)
    {
        $superAdmin = $request->user();

        $admin = tenancy()->run($hospital, fn () => User::where('hospital_id', $hospital->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Hospital Admin'))
            ->where('status', 'active')
            ->first());

        abort_unless($admin, 404, 'This hospital has no active Hospital Admin account.');

        AuditLog::record('impersonate', $hospital, [], ['as_user' => $admin->email], "{$superAdmin->name} opened {$hospital->name} as {$admin->name}");

        Auth::login($admin);
        $request->session()->regenerate();
        $request->session()->put('impersonator_id', $superAdmin->id);

        return redirect()->route('tenant.dashboard', ['hospital' => $hospital->slug]);
    }

    public function leave(Request $request)
    {
        $id = $request->session()->pull('impersonator_id');
        $superAdmin = $id ? User::where('id', $id)->where('is_super_admin', true)->first() : null;

        abort_unless($superAdmin, 403);

        Auth::login($superAdmin);
        $request->session()->regenerate();

        return redirect()->route('admin.hospitals.index');
    }
}
