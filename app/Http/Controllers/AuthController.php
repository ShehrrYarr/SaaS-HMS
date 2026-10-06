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
        $isFbr = $request->user()?->isFbrOfficer();
        $wasSuperAdmin = $request->user()?->is_super_admin;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($hospital) {
            $login = $isFbr ? 'fbr.login' : ($isPatient ? 'portal.login' : 'tenant.login');

            return redirect()->route($login, ['hospital' => $hospital->slug]);
        }

        return redirect()->route($wasSuperAdmin ? 'admin.login' : 'home');
    }
}
