<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\Invoice;
use App\Models\IpdAdmission;
use App\Models\IpdCharge;
use App\Models\Patient;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IpdService
{
    public function __construct(protected BillingService $billing) {}

    public function admit(Patient $patient, array $data): IpdAdmission
    {
        if ($patient->admissions()->where('status', 'admitted')->exists()) {
            throw ValidationException::withMessages(['patient_id' => 'This patient is already admitted.']);
        }

        return DB::transaction(function () use ($patient, $data) {
            $bed = Bed::lockForUpdate()->findOrFail($data['bed_id']);
            if ($bed->status !== 'available' && $bed->status !== 'reserved') {
                throw ValidationException::withMessages(['bed_id' => 'Bed '.$bed->bed_no.' is not available.']);
            }

            $admission = IpdAdmission::create([
                'admission_no' => Sequence::code('ipd', 'IPD'),
                'patient_id' => $patient->id,
                'doctor_id' => $data['doctor_id'],
                'department_id' => $data['department_id'] ?? null,
                'bed_id' => $bed->id,
                'admitted_at' => $data['admitted_at'] ?? now(),
                'admission_type' => $data['admission_type'] ?? 'planned',
                'reason' => $data['reason'] ?? null,
                'provisional_diagnosis' => $data['provisional_diagnosis'] ?? null,
                'tpa_id' => $data['tpa_id'] ?? $patient->tpa_id,
                'insurance_policy_no' => $data['insurance_policy_no'] ?? $patient->insurance_policy_no,
                'guardian_name' => $data['guardian_name'] ?? null,
                'guardian_phone' => $data['guardian_phone'] ?? null,
                'deposit_amount' => $data['deposit_amount'] ?? 0,
                'expected_discharge_date' => $data['expected_discharge_date'] ?? null,
                'status' => 'admitted',
                'created_by' => auth()->id(),
            ]);

            BedAllocation::create([
                'ipd_admission_id' => $admission->id,
                'bed_id' => $bed->id,
                'from_at' => $admission->admitted_at,
                'charge_per_day' => $bed->dailyCharge(),
                'reason' => 'admission',
                'created_by' => auth()->id(),
            ]);
            $bed->update(['status' => 'occupied']);

            return $admission;
        });
    }

    public function transfer(IpdAdmission $admission, int $bedId, string $reason = 'transfer'): void
    {
        $this->ensureAdmitted($admission);

        DB::transaction(function () use ($admission, $bedId, $reason) {
            $newBed = Bed::lockForUpdate()->findOrFail($bedId);
            if ($newBed->status !== 'available') {
                throw ValidationException::withMessages(['bed_id' => 'Bed '.$newBed->bed_no.' is not available.']);
            }

            $admission->allocations()->whereNull('to_at')->update(['to_at' => now()]);
            $admission->bed?->update(['status' => 'cleaning']);

            BedAllocation::create([
                'ipd_admission_id' => $admission->id,
                'bed_id' => $newBed->id,
                'from_at' => now(),
                'charge_per_day' => $newBed->dailyCharge(),
                'reason' => $reason ?: 'transfer',
                'created_by' => auth()->id(),
            ]);
            $newBed->update(['status' => 'occupied']);
            $admission->update(['bed_id' => $newBed->id]);
        });
    }

    public function addCharge(IpdAdmission $admission, array $data): IpdCharge
    {
        $this->ensureAdmitted($admission);
        $quantity = (float) ($data['quantity'] ?? 1);
        $unit = (float) $data['unit_price'];

        return IpdCharge::create([
            'ipd_admission_id' => $admission->id,
            'category' => $data['category'] ?? 'other',
            'description' => $data['description'],
            'quantity' => $quantity,
            'unit_price' => $unit,
            'amount' => round($quantity * $unit, 2),
            'charged_at' => $data['charged_at'] ?? now(),
            'source_type' => isset($data['source']) ? $data['source']->getMorphClass() : null,
            'source_id' => isset($data['source']) ? $data['source']->getKey() : null,
            'doctor_id' => $data['doctor_id'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    /** Discharge: free the bed and produce the final consolidated invoice. */
    public function discharge(IpdAdmission $admission, array $data): Invoice
    {
        $this->ensureAdmitted($admission);

        return DB::transaction(function () use ($admission, $data) {
            $admission->allocations()->whereNull('to_at')->update(['to_at' => now()]);
            $admission->load(['allocations.bed.ward', 'charges', 'patient']);

            $tax = $this->billing->defaultTaxPercent();
            $items = [];
            foreach ($admission->allocations as $allocation) {
                $days = max(1, (int) ceil($allocation->from_at->diffInHours($allocation->to_at ?? now()) / 24));
                $items[] = [
                    'service_type' => 'ipd',
                    'description' => 'Bed charges – '.$allocation->bed->label.' ('.$days.' day'.($days > 1 ? 's' : '').')',
                    'quantity' => $days,
                    'unit_price' => (float) $allocation->charge_per_day,
                    'tax_percent' => $tax,
                    'source' => $allocation,
                ];
            }
            foreach ($admission->charges->where('billed', false) as $charge) {
                $items[] = [
                    'service_type' => in_array($charge->category, ['medicine', 'lab', 'radiology', 'ot', 'bloodbank']) ? ($charge->category === 'medicine' ? 'pharmacy' : $charge->category) : 'ipd',
                    'description' => $charge->description,
                    'quantity' => (float) $charge->quantity,
                    'unit_price' => (float) $charge->unit_price,
                    'tax_percent' => $charge->category === 'medicine' ? 0 : $tax,
                    'source' => $charge,
                    'doctor_id' => $charge->doctor_id,
                ];
            }

            $invoice = $this->billing->createInvoice($admission->patient, $items, [
                'ipd_admission_id' => $admission->id,
                'tpa_id' => $admission->tpa_id,
                'notes' => 'Final IPD bill for admission '.$admission->admission_no,
            ]);
            $admission->charges()->where('billed', false)->update(['billed' => true]);

            if ((float) $admission->deposit_amount > 0) {
                $this->billing->addPayment($invoice, min((float) $admission->deposit_amount, $invoice->balance), 'cash', 'Advance deposit', false, 'Adjusted from admission deposit');
            }

            $admission->update([
                'status' => 'discharged',
                'discharged_at' => now(),
                'discharge_type' => $data['discharge_type'] ?? 'normal',
                'discharge_summary' => $data['discharge_summary'] ?? null,
                'discharge_condition' => $data['discharge_condition'] ?? null,
                'discharge_instructions' => $data['discharge_instructions'] ?? null,
                'follow_up_date' => $data['follow_up_date'] ?? null,
                'invoice_id' => $invoice->id,
                'discharged_by' => auth()->id(),
            ]);
            $admission->bed?->update(['status' => 'cleaning']);

            return $invoice;
        });
    }

    protected function ensureAdmitted(IpdAdmission $admission): void
    {
        if ($admission->status !== 'admitted') {
            throw ValidationException::withMessages(['admission' => 'This admission is already closed.']);
        }
    }
}
