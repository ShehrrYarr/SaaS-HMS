<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Streams private uploads (storage/app/private). Files live under
 * hospitals/{id}/... so a user may only read files of their own hospital;
 * patients may only read their own folder and the hospital branding, and
 * FBR accounts only the branding.
 */
class FileController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        $path = str_replace(['..', '\\'], '', $path);
        $user = $request->user();

        if (! $user->is_super_admin) {
            $prefix = "hospitals/{$user->hospital_id}/";
            abort_unless(str_starts_with($path, $prefix), 403);

            // This route runs outside /h/{slug}, so point role lookups at the user's hospital first
            // (with no team set, Spatie finds no roles and the checks below would never apply).
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->hospital_id);
            $user->unsetRelation('roles');

            if ($user->isPatientOnly()) {
                $patientId = Patient::withoutHospitalScope()->where('user_id', $user->id)->value('id');
                $allowed = [$prefix.'branding/', $prefix."patients/{$patientId}/"];
                abort_unless(collect($allowed)->contains(fn ($p) => str_starts_with($path, $p)), 403);
            }

            // The FBR register shows no uploads: FBR accounts only need the hospital logo.
            if ($user->isFbrOfficer()) {
                abort_unless(str_starts_with($path, $prefix.'branding/'), 403);
            }
        }

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
