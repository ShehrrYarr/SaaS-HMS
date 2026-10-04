<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Staff;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpdService
{
    public function __construct(protected BillingService $billing) {}

    /**
     * Create an OPD visit (walk-in or from an appointment), issue a queue token and bill the consultation fee.
     */
    public function createVisit(Patient $patient, Staff $doctor, array $data = [], bool $bill = true): OpdVisit
    {
        return DB::transaction(function () use ($patient, $doctor, $data, $bill) {
            $visitType = $data['visit_type'] ?? ($patient->opdVisits()->where('doctor_id', $doctor->id)->exists() ? 'follow_up' : 'new');
            $fee = array_key_exists('fee', $data) && $data['fee'] !== null && $data['fee'] !== ''
                ? (float) $data['fee']
                : (float) ($visitType === 'follow_up' && $doctor->follow_up_fee > 0 ? $doctor->follow_up_fee : $doctor->consultation_fee);

            $visit = OpdVisit::create([
                'visit_no' => Sequence::code('opd', 'OPD'),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'department_id' => $doctor->department_id,
                'visit_date' => now(),
                'visit_type' => $visitType,
                'token_no' => $data['token_no'] ?? Appointment::nextToken($doctor->id, today()->toDateString()),
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'status' => 'waiting',
                'fee' => $fee,
                'created_by' => auth()->id(),
            ]);

            if ($bill && $fee > 0 && hospital()?->hasModule('billing')) {
                $invoice = $this->billing->createInvoice($patient, [[
                    'service_type' => 'opd',
                    'description' => 'Consultation – '.$doctor->display_name.' ('.label($visitType).')',
                    'unit_price' => $fee,
                    'tax_percent' => $this->billing->defaultTaxPercent(),
                    'source' => $visit,
                    'doctor_id' => $doctor->id,
                ]], ['opd_visit_id' => $visit->id]);
                $visit->update(['invoice_id' => $invoice->id]);
            }

            return $visit;
        });
    }

    public function checkIn(Appointment $appointment, bool $bill = true): OpdVisit
    {
        if (in_array($appointment->status, ['cancelled', 'completed', 'no_show'])) {
            throw ValidationException::withMessages(['appointment' => 'This appointment cannot be checked in.']);
        }
        if ($existing = $appointment->opdVisit) {
            return $existing;
        }

        return DB::transaction(function () use ($appointment, $bill) {
            $token = $appointment->token_no ?: Appointment::nextToken($appointment->doctor_id, $appointment->appointment_date->toDateString());
            $appointment->update(['status' => 'checked_in', 'checked_in_at' => now(), 'token_no' => $token]);

            return $this->createVisit($appointment->patient, $appointment->doctor, [
                'appointment_id' => $appointment->id,
                'token_no' => $token,
                'chief_complaint' => $appointment->reason,
                'fee' => $appointment->fee > 0 ? $appointment->fee : null,
            ], $bill);
        });
    }

    public function startConsultation(OpdVisit $visit): void
    {
        $visit->update(['status' => 'in_consultation', 'consultation_started_at' => $visit->consultation_started_at ?? now()]);
        $visit->appointment?->update(['status' => 'in_consultation']);
    }

    public function completeConsultation(OpdVisit $visit, ?string $followUpDate = null): void
    {
        $visit->update(['status' => 'completed', 'consultation_ended_at' => now(), 'follow_up_date' => $followUpDate ?: $visit->follow_up_date]);
        $visit->appointment?->update(['status' => 'completed']);
    }
}
