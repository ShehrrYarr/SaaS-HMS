<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\LabDevice;
use App\Services\DiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class LabDeviceController extends Controller
{
    public function store(Request $request, DiagnosticsService $diagnostics)
    {
        $token = (string) $request->bearerToken();
        abort_if($token === '', 401, 'Missing device token.');

        $device = LabDevice::withoutHospitalScope()->where('api_token', LabDevice::hashToken($token))->where('is_active', true)->first();
        abort_unless($device, 401, 'Invalid device token.');

        $hospital = Hospital::findOrFail($device->hospital_id);
        abort_if($hospital->isSuspended() || ! $hospital->hasModule('laboratory'), 403, 'Laboratory module unavailable.');

        $data = $request->validate([
            'barcode' => 'required|string|max:40',
            'results' => 'required|array|min:1',
            'results.*' => 'nullable',
        ]);

        return tenancy()->run($hospital, function () use ($device, $data, $diagnostics) {
            try {
                $item = $diagnostics->ingestFromDevice($device, $data['barcode'], $data['results']);
            } catch (ModelNotFoundException) {
                return response()->json(['message' => 'Unknown sample barcode.'], 404);
            }

            return response()->json([
                'message' => 'Results received.',
                'order' => $item->order->order_no,
                'test' => $item->test->code,
                'status' => $item->status,
                'flags' => $item->results()->with('parameter')->get()->mapWithKeys(fn ($r) => [$r->parameter->code ?? $r->parameter->name => $r->flag]),
            ]);
        });
    }
}
