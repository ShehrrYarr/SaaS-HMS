<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function logout(Request $request)
    {
        $hospital = hospital();
        $isPatient = $request->user()?->isPatientOnly();
        $wasSuperAdmin = $request->user()?->is_super_admin;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($hospital) {
            return redirect()->route($isPatient ? 'portal.login' : 'tenant.login', ['hospital' => $hospital->slug]);
        }

        return redirect()->route($wasSuperAdmin ? 'admin.login' : 'home');
    }
}
