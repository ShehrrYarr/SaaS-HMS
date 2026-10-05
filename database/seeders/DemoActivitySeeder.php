<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Bed;
use App\Models\BloodRequest;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Hospital;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\OtRoom;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\RadiologyTest;
use App\Models\Staff;
use App\Models\Surgery;
use App\Models\User;
use App\Models\Vital;
use App\Services\BillingService;
use App\Services\DiagnosticsService;
use App\Services\IpdService;
use App\Services\LedgerService;
use App\Services\OpdService;
use App\Services\PharmacyService;
use App\Support\Sequence;
use Carbon\Carbon;

/**
 * Realistic day-to-day activity for the demo hospital, produced through the
 * same services the UI uses (so totals, stock and commissions stay consistent).
 */
class DemoActivitySeeder
{
    public function seed(Hospital $hospital): void
    {
        $reception = User::where('hospital_id', $hospital->id)->where('email', 'reception@cityhospital.test')->first();
        $doctorUser = User::where('hospital_id', $hospital->id)->where('email', 'doctor@cityhospital.test')->first();
        $labUser = User::where('hospital_id', $hospital->id)->where('email', 'lab@cityhospital.test')->first();
        $radUser = User::where('hospital_id', $hospital->id)->where('email', 'radiology@cityhospital.test')->first();
        auth()->setUser($reception);

        $opd = app(OpdService::class);
        $billing = app(BillingService::class);
        $diag = app(DiagnosticsService::class);
        $ipd = app(IpdService::class);
        $pharmacy = app(PharmacyService::class);
        $ledger = app(LedgerService::class);
        $doctors = Staff::doctors()->get();
        $cash = BankAccount::cash();
        $hbl = BankAccount::where('name', 'HBL')->first() ?? $cash;
        $meezan = BankAccount::where('name', 'Meezan Bank')->first() ?? $cash;
        // Ledger lines are written "now"; move them to when the demo event happened.
        $backdate = fn ($source, $at) => BankTransaction::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->update(['transacted_at' => $at]);
        $patients = Patient::orderBy('id')->get();

        // ---- 14 days of OPD history (feeds dashboard & finance charts)
        foreach (range(13, 1) as $daysAgo) {
            $date = today()->subDays($daysAgo)->setTime(10, 0);
            foreach (range(1, random_int(2, 5)) as $n) {
                $visit = $opd->createVisit($patients->random(), $doctors->random(), ['chief_complaint' => collect(['Fever', 'Cough', 'Back pain', 'Headache', 'Follow-up', 'Abdominal pain'])->random()]);
                $when = $date->copy()->addMinutes($n * 20);
                $visit->update(['visit_date' => $when, 'status' => 'completed', 'consultation_started_at' => $when, 'consultation_ended_at' => $when->copy()->addMinutes(12)]);
                if ($visit->invoice_id) {
                    $invoice = Invoice::find($visit->invoice_id);
                    $invoice->update(['invoice_date' => $when->toDateString(), 'created_at' => $when]);
                    if ($n % 4 !== 0) {
                        $payment = $billing->addPayment($invoice, $invoice->balance, $n % 2 ? $cash : $hbl);
                        $payment->update(['paid_at' => $when]);
                        $backdate($payment, $when);
                    }
                }
            }
        }

        // ---- Today's queue: check in three appointments, complete one full consultation
        auth()->setUser($reception);
        $appointments = Appointment::whereDate('appointment_date', today())->where('status', 'booked')->orderBy('start_time')->take(3)->get();
        $visits = $appointments->map(fn ($a) => $opd->checkIn($a));
        if ($first = $visits->first()) {
            auth()->setUser($doctorUser);
            $opd->startConsultation($first);
            Vital::create(['patient_id' => $first->patient_id, 'visitable_type' => $first->getMorphClass(), 'visitable_id' => $first->id, 'bp_systolic' => 142, 'bp_diastolic' => 91, 'pulse' => 84, 'temperature' => 37.2, 'spo2' => 97, 'weight' => 78, 'height' => 172, 'recorded_by' => $reception->id, 'recorded_at' => now()->subMinutes(30)]);
            ClinicalNote::create(['patient_id' => $first->patient_id, 'visitable_type' => $first->getMorphClass(), 'visitable_id' => $first->id, 'type' => 'consultation',
                'subjective' => 'Fever for 3 days with body aches. No cough.', 'objective' => 'Temp 37.2°C, throat mildly congested, chest clear.',
                'assessment' => 'Viral fever. Elevated BP – rule out essential hypertension.', 'plan' => 'CBC, FBS. Paracetamol. Review in 1 week with BP log.', 'author_id' => $doctorUser->id]);
            Diagnosis::create(['patient_id' => $first->patient_id, 'visitable_type' => $first->getMorphClass(), 'visitable_id' => $first->id, 'icd_code' => 'B34.9', 'description' => 'Viral infection, unspecified', 'type' => 'provisional', 'diagnosed_by' => $doctorUser->id]);
            $rx = Prescription::create(['prescription_no' => Sequence::code('prescription', 'RX'), 'patient_id' => $first->patient_id, 'doctor_id' => $first->doctor_id, 'visitable_type' => $first->getMorphClass(), 'visitable_id' => $first->id, 'advice' => 'Plenty of fluids, rest. Return if fever persists > 3 days.', 'follow_up_date' => today()->addWeek(), 'status' => 'issued']);
            $panadol = Medicine::where('name', 'like', 'Panadol%')->first();
            $rx->items()->create(['medicine_id' => $panadol?->id, 'medicine_name' => $panadol?->label ?? 'Paracetamol 500mg', 'dosage' => '500mg', 'frequency' => '1-1-1', 'duration' => '3 days', 'route' => 'Oral', 'quantity' => 9, 'instructions' => 'After meals']);
            $omep = Medicine::where('name', 'like', 'Omeprazole%')->first();
            $rx->items()->create(['medicine_id' => $omep?->id, 'medicine_name' => $omep?->label ?? 'Omeprazole 20mg', 'dosage' => '20mg', 'frequency' => '1-0-0', 'duration' => '5 days', 'route' => 'Oral', 'quantity' => 5, 'instructions' => 'Before breakfast']);

            $order = $diag->orderLab($first->patient, LabTest::whereIn('code', ['CBC', 'FBS'])->pluck('id')->all(), $first->doctor, $first, 'urgent');
            auth()->setUser($labUser);
            foreach ($order->items as $item) {
                $diag->collectSample($item);
                $values = $item->test->parameters->mapWithKeys(fn ($p) => [$p->id => match ($p->code) {
                    'HGB' => '13.9', 'WBC' => '11.8', 'RBC' => '4.9', 'HCT' => '43', 'PLT' => '182', 'NEU' => '78', 'LYM' => '18', 'GLU' => '112', default => '1',
                }])->all();
                $diag->saveResults($item->fresh(), $values);
            }
            auth()->setUser($doctorUser);
            foreach ($order->fresh()->items as $item) {
                $diag->approve($item);
            }

            $xray = $diag->orderRadiology($first->patient, RadiologyTest::where('code', 'XR-CH')->pluck('id')->all(), $first->doctor, $first)[0] ?? null;
            if ($xray) {
                $xray->update(['status' => 'approved', 'performed_at' => now()->subMinutes(20), 'performed_by' => $radUser->id, 'radiologist_id' => $radUser->id, 'reported_at' => now()->subMinutes(10), 'approved_at' => now()->subMinutes(5),
                    'findings' => "Both lung fields are clear. No consolidation or effusion.\nCardiac silhouette within normal limits. Costophrenic angles clear.",
                    'impression' => 'Normal chest radiograph.']);
            }
        }

        // ---- IPD: one current admission with charges, one discharged with final bill
        auth()->setUser($reception);
        $beds = Bed::where('status', 'available')->whereHas('ward', fn ($q) => $q->whereIn('type', ['general', 'private']))->take(2)->get();
        if ($beds->count() === 2) {
            $current = $ipd->admit($patients[3], ['doctor_id' => $doctors[1]->id, 'bed_id' => $beds[0]->id, 'admission_type' => 'emergency', 'reason' => 'Community acquired pneumonia', 'provisional_diagnosis' => 'CAP – right lower lobe', 'deposit_amount' => 20000, 'deposit_account_id' => $cash->id]);
            $current->update(['admitted_at' => now()->subDays(2)]);
            $backdate($current, now()->subDays(2));
            $current->allocations()->update(['from_at' => now()->subDays(2)]);
            $ipd->addCharge($current, ['category' => 'nursing', 'description' => 'Nursing Care (per day)', 'quantity' => 2, 'unit_price' => 2000]);
            $ipd->addCharge($current, ['category' => 'doctor_visit', 'description' => 'Doctor Visit (IPD)', 'quantity' => 2, 'unit_price' => 2500, 'doctor_id' => $doctors[1]->id]);
            Vital::create(['patient_id' => $current->patient_id, 'visitable_type' => $current->getMorphClass(), 'visitable_id' => $current->id, 'bp_systolic' => 118, 'bp_diastolic' => 76, 'pulse' => 104, 'temperature' => 38.4, 'spo2' => 92, 'respiratory_rate' => 24, 'recorded_by' => $reception->id, 'recorded_at' => now()->subHours(5)]);
            $pharmacy->sell([['medicine_id' => Medicine::where('name', 'like', 'Ceftriaxone%')->value('id'), 'quantity' => 4], ['medicine_id' => Medicine::where('name', 'like', 'Normal Saline%')->value('id'), 'quantity' => 6]],
                ['patient_id' => $current->patient_id, 'ipd_admission_id' => $current->id, 'payment_method' => 'ipd_credit']);

            $past = $ipd->admit($patients[6], ['doctor_id' => $doctors[0]->id, 'bed_id' => $beds[1]->id, 'admission_type' => 'planned', 'reason' => 'Chest pain evaluation', 'deposit_amount' => 10000, 'deposit_account_id' => $meezan->id]);
            $past->update(['admitted_at' => now()->subDays(5)]);
            $backdate($past, now()->subDays(5));
            $past->allocations()->update(['from_at' => now()->subDays(5)]);
            $ipd->addCharge($past, ['category' => 'procedure', 'description' => 'ECG', 'unit_price' => 1000]);
            $final = $ipd->discharge($past, ['discharge_type' => 'normal', 'discharge_summary' => "Admitted with atypical chest pain. Serial ECGs and troponins negative.\nManaged conservatively; pain resolved.", 'discharge_condition' => 'Stable, ambulatory.', 'discharge_instructions' => 'Tab Amlodipine 5mg once daily. Low-salt diet.', 'follow_up_date' => today()->addDays(10)]);
            if ($final->balance > 0) {
                $billing->addPayment($final, $final->balance, $meezan, 'Online transfer');
            }
            $past->bed?->update(['status' => 'available']);
        }

        // ---- Pharmacy counter sales
        foreach (range(1, 6) as $i) {
            $meds = Medicine::withStock()->get()->filter(fn ($m) => (int) $m->stock > 20 && ! $m->requires_prescription)->random(2);
            $sale = $pharmacy->sell($meds->map(fn ($m) => ['medicine_id' => $m->id, 'quantity' => random_int(1, 3)])->values()->all(),
                ['customer_name' => fake()->name(), 'bank_account_id' => ($i % 3 ? $cash : $hbl)->id]);
            $sale->update(['created_at' => $soldAt = now()->subDays(random_int(0, 6))->setTime(random_int(9, 19), random_int(0, 59))]);
            $backdate($sale, $soldAt);
        }

        // ---- Insurance claim for an insured patient's invoice
        $insured = $patients->firstWhere('tpa_id', '!=', null);
        if ($insured) {
            $inv = $billing->createInvoice($insured, [['service_type' => 'service', 'description' => 'Health check package', 'unit_price' => 8000]]);
            $inv->update(['insurance_amount' => 6000]);
            $inv->recalculate();
            InsuranceClaim::create(['claim_no' => Sequence::code('claim', 'CLM'), 'tpa_id' => $insured->tpa_id, 'patient_id' => $insured->id, 'invoice_id' => $inv->id,
                'policy_no' => $insured->insurance_policy_no, 'claim_amount' => 6000, 'status' => 'submitted', 'submitted_at' => today()->subDay(), 'created_by' => $reception->id]);
        }

        // ---- Expenses this month
        $cats = ExpenseCategory::pluck('id', 'name');
        foreach ([['Electricity bill', 'Utilities', 85000, $hbl], ['Generator fuel', 'Utilities', 32000, $cash], ['Building rent', 'Rent', 450000, $hbl],
            ['AC servicing', 'Maintenance', 18000, $cash], ['Gloves & syringes', 'Medical Supplies', 42000, $meezan], ['Staff refreshments', 'Miscellaneous', 6500, $cash]] as $i => [$title, $cat, $amount, $account]) {
            $expense = Expense::create(['expense_category_id' => $cats[$cat] ?? null, 'title' => $title, 'amount' => $amount, 'expense_date' => today()->startOfMonth()->addDays(min($i * 3, today()->day - 1))->toDateString(),
                'paid_to' => fake()->company(), 'payment_method' => $account->type, 'bank_account_id' => $account->id, 'created_by' => $reception->id]);
            $ledger->postExpense($expense);
        }

        // ---- Yesterday's cash deposited into the bank
        $ledger->transfer($cash, $hbl, 40000, 'Daily cash deposit', now()->subDay()->setTime(17, 30));

        // ---- Attendance today
        foreach (Staff::active()->get() as $i => $s) {
            Attendance::create(['staff_id' => $s->id, 'date' => today()->toDateString(), 'status' => $i % 7 === 3 ? 'late' : ($i % 9 === 5 ? 'leave' : 'present'), 'check_in' => $i % 9 === 5 ? null : ($i % 7 === 3 ? '08:35' : '07:55'), 'marked_by' => $reception->id]);
        }

        // ---- OT & blood bank
        $room = OtRoom::first();
        $surgeon = $doctors->firstWhere('specialization', 'Orthopedics') ?? $doctors->first();
        $otPatient = $patients[9];
        Surgery::create(['surgery_no' => Sequence::code('surgery', 'OT'), 'patient_id' => $otPatient->id, 'ot_room_id' => $room->id, 'procedure_name' => 'Arthroscopic knee meniscectomy', 'surgery_type' => 'major',
            'scheduled_start' => today()->addDay()->setTime(9, 0), 'scheduled_end' => today()->addDay()->setTime(11, 0), 'status' => 'scheduled', 'surgeon_id' => $surgeon->id, 'anesthesia_type' => 'spinal', 'charges' => 85000, 'created_by' => $reception->id]);
        BloodRequest::create(['request_no' => Sequence::code('blood-request', 'BRQ'), 'patient_id' => $patients[3]->id, 'blood_group' => $patients[3]->blood_group ?? 'O+', 'component' => 'prbc', 'units' => 1, 'priority' => 'urgent', 'status' => 'pending', 'notes' => 'Hb 7.8 g/dL', 'requested_by' => $reception->id]);

        auth()->forgetUser();
    }
}
