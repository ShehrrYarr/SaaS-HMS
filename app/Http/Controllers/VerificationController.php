<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\LabOrder;

/**
 * Public QR verification for lab reports: confirms a report was issued and
 * approved by the hospital, without exposing clinical results.
 */
class VerificationController extends Controller
{
    public function lab(string $code)
    {
        $order = tenancy()->withoutScope(fn () => LabOrder::with(['patient', 'items.test', 'approver'])
            ->where('verification_code', $code)->whereHas('items', fn ($q) => $q->where('status', 'approved'))->first());

        $hospital = $order ? Hospital::find($order->hospital_id) : null;

        return response()->view('verify.lab', [
            'order' => $order,
            'hospital' => $hospital,
            'patientName' => $order ? $this->mask($order->patient->full_name) : null,
        ], $order ? 200 : 404);
    }

    protected function mask(string $name): string
    {
        return collect(explode(' ', $name))->map(fn ($p) => mb_substr($p, 0, 1).str_repeat('*', max(1, mb_strlen($p) - 1)))->join(' ');
    }
}
