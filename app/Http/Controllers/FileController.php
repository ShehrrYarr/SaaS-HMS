<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Streams private uploads (storage/app/private). Files live under
 * hospitals/{id}/... so a user may only read files of their own hospital;
 * patients may only read their own folder and the hospital branding.
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

            if ($user->isPatientOnly()) {
                $patientId = Patient::withoutHospitalScope()->where('user_id', $user->id)->value('id');
                $allowed = [$prefix.'branding/', $prefix."patients/{$patientId}/"];
                abort_unless(collect($allowed)->contains(fn ($p) => str_starts_with($path, $p)), 403);
            }
        }

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
