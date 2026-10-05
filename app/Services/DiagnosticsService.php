<?php

namespace App\Services;

use App\Models\InvoiceItem;
use App\Models\IpdAdmission;
use App\Models\IpdCharge;
use App\Models\LabDevice;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabResult;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\RadiologyTest;
use App\Models\Staff;
use App\Notifications\HmsNotification;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Laboratory & radiology workflows:
 * order -> sample collection (barcode) -> processing -> result entry -> approval -> report.
 */
class DiagnosticsService
{
    public function __construct(protected BillingService $billing, protected IpdService $ipd) {}

    public function orderLab(Patient $patient, array $testIds, ?Staff $doctor = null, ?Model $visitable = null, string $priority = 'routine', ?string $notes = null, bool $bill = true): LabOrder
    {
        $tests = LabTest::whereIn('id', $testIds)->where('is_active', true)->get();
        if ($tests->isEmpty()) {
            throw ValidationException::withMessages(['tests' => 'Select at least one test.']);
        }

        return DB::transaction(function () use ($patient, $tests, $doctor, $visitable, $priority, $notes, $bill) {
            $order = LabOrder::create([
                'order_no' => Sequence::code('lab', 'LAB'),
                'patient_id' => $patient->id,
                'doctor_id' => $doctor?->id,
                'visitable_type' => $visitable?->getMorphClass(),
                'visitable_id' => $visitable?->getKey(),
                'priority' => $priority,
                'status' => 'ordered',
                'clinical_notes' => $notes,
                'ordered_by' => auth()->id(),
                'ordered_at' => now(),
            ]);

            foreach ($tests as $test) {
                $order->items()->create(['lab_test_id' => $test->id, 'price' => $test->price, 'status' => 'pending']);
            }

            if ($bill) {
                $this->bill($patient, $visitable, $tests->map(fn ($t) => [
                    'service_type' => 'lab', 'description' => 'Lab: '.$t->name, 'unit_price' => (float) $t->price, 'source' => $order,
                ])->all(), $order, 'lab');
            }

            return $order;
        });
    }

    public function collectSample(LabOrderItem $item): LabOrderItem
    {
        if ($item->status !== 'pending') {
            return $item;
        }
        $item->update([
            'status' => 'collected',
            'sample_barcode' => $item->sample_barcode ?: strtoupper((hospital()?->code ?? 'L').str_pad((string) $item->id, 8, '0', STR_PAD_LEFT)),
            'sample_collected_at' => now(),
            'collected_by' => auth()->id(),
        ]);
        $item->order->refreshStatus();

        return $item;
    }

    /** @param array<int,string|null> $values parameter_id => value */
    public function saveResults(LabOrderItem $item, array $values, bool $complete = true, ?string $remarks = null, ?LabDevice $device = null): void
    {
        if (in_array($item->status, ['approved', 'cancelled'])) {
            throw ValidationException::withMessages(['results' => 'Approved results cannot be changed.']);
        }

        DB::transaction(function () use ($item, $values, $complete, $remarks, $device) {
            $gender = $item->order->patient->gender;
            $parameters = $item->test->parameters->keyBy('id');

            foreach ($values as $parameterId => $value) {
                $parameter = $parameters[$parameterId] ?? null;
                if (! $parameter) {
                    continue;
                }
                $value = is_string($value) ? trim($value) : $value;
                LabResult::updateOrCreate(
                    ['lab_order_item_id' => $item->id, 'lab_test_parameter_id' => $parameter->id],
                    ['value' => $value === '' ? null : $value, 'flag' => $this->flag($parameter, $value, $gender)]
                );
            }

            $item->update([
                'status' => $complete ? 'completed' : 'processing',
                'sample_collected_at' => $item->sample_collected_at ?? now(),
                'results_entered_at' => now(),
                'results_entered_by' => auth()->id(),
                'remarks' => $remarks ?? $item->remarks,
                'lab_device_id' => $device?->id ?? $item->lab_device_id,
            ]);
            $item->order->refreshStatus();
        });
    }

    public function approve(LabOrderItem $item): void
    {
        if ($item->status !== 'completed') {
            throw ValidationException::withMessages(['results' => 'Only completed results can be approved.']);
        }
        $item->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => auth()->id()]);
        $order = $item->order->fresh();
        $order->refreshStatus();

        if ($order->fresh()->status === 'approved') {
            $this->notifyReportReady($order->fresh(['patient.user']));
        }
    }

    /** Device / LIS integration: results keyed by parameter code against a sample barcode. */
    public function ingestFromDevice(LabDevice $device, string $barcode, array $results): LabOrderItem
    {
        $item = LabOrderItem::with('test.parameters', 'order.patient')->where('sample_barcode', $barcode)->firstOrFail();
        $byCode = $item->test->parameters->keyBy(fn ($p) => strtoupper((string) $p->code));
        $values = [];
        foreach ($results as $code => $value) {
            if ($parameter = $byCode[strtoupper($code)] ?? null) {
                $values[$parameter->id] = (string) $value;
            }
        }
        $this->saveResults($item, $values, true, null, $device);
        $device->update(['last_seen_at' => now()]);

        return $item->fresh();
    }

    public function orderRadiology(Patient $patient, array $testIds, ?Staff $doctor = null, ?Model $visitable = null, string $priority = 'routine', ?string $history = null, bool $bill = true): array
    {
        $tests = RadiologyTest::whereIn('id', $testIds)->where('is_active', true)->get();
        if ($tests->isEmpty()) {
            throw ValidationException::withMessages(['tests' => 'Select at least one imaging study.']);
        }

        return DB::transaction(function () use ($patient, $tests, $doctor, $visitable, $priority, $history, $bill) {
            $orders = [];
            foreach ($tests as $test) {
                $orders[] = $order = RadiologyOrder::create([
                    'order_no' => Sequence::code('radiology', 'RAD'),
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor?->id,
                    'radiology_test_id' => $test->id,
                    'visitable_type' => $visitable?->getMorphClass(),
                    'visitable_id' => $visitable?->getKey(),
                    'priority' => $priority,
                    'status' => 'ordered',
                    'clinical_history' => $history,
                    'price' => $test->price,
                    'ordered_by' => auth()->id(),
                ]);
                if ($bill) {
                    $this->bill($patient, $visitable, [[
                        'service_type' => 'radiology', 'description' => 'Imaging: '.$test->name, 'unit_price' => (float) $test->price, 'source' => $order,
                    ]], $order, 'radiology');
                }
            }

            return $orders;
        });
    }

    public function flag($parameter, $value, ?string $gender): ?string
    {
        if ($parameter->result_type === 'option' && $value !== null && $value !== '' && $parameter->ref_text) {
            return strcasecmp((string) $value, $parameter->ref_text) === 0 ? 'normal' : 'abnormal';
        }

        return $parameter->flagFor($value === null ? null : (string) $value, $gender);
    }

    /**
     * Take a cancelled test off what the patient owes. Returns a note for staff when the test
     * was already paid (or billed at discharge) and has to be refunded from the bill instead.
     */
    public function unbill(Model $order, string $description): ?string
    {
        $source = fn ($q) => $q->where('source_type', $order->getMorphClass())->where('source_id', $order->getKey())->where('description', $description);

        $charge = IpdCharge::where($source)->first();
        if ($charge && ! $charge->billed) {
            $charge->delete();

            return null;
        }
        $item = InvoiceItem::where($source)->with('invoice')->first();
        $invoice = $item?->invoice;
        if (! $invoice || $invoice->status === 'cancelled') {
            return $charge ? "{$description} is already on the final IPD bill; adjust that bill instead." : null;
        }
        if ($invoice->paid_amount > $invoice->total - $item->total) {
            return "{$description} was already paid on {$invoice->invoice_no}; refund ".money($item->total).' from that invoice.';
        }
        if ($invoice->items()->count() === 1) {
            $this->billing->cancel($invoice, 'Test cancelled');
        } else {
            $this->billing->removeItem($item);
        }

        return null;
    }

    /** IPD patients accumulate charges on the admission; others get an invoice. */
    protected function bill(Patient $patient, ?Model $visitable, array $items, Model $order, string $category): void
    {
        if ($visitable instanceof IpdAdmission && $visitable->status === 'admitted') {
            foreach ($items as $item) {
                $this->ipd->addCharge($visitable, ['category' => $category, 'description' => $item['description'], 'unit_price' => $item['unit_price'], 'source' => $order]);
            }

            return;
        }
        if (! hospital()?->hasModule('billing')) {
            return;
        }
        $tax = $this->billing->defaultTaxPercent();
        $invoice = $this->billing->createInvoice($patient, array_map(fn ($i) => $i + ['tax_percent' => $tax], $items), [
            'opd_visit_id' => $visitable instanceof \App\Models\OpdVisit ? $visitable->id : null,
        ]);
        $order->update(['invoice_id' => $invoice->id]);
    }

    protected function notifyReportReady(LabOrder $order): void
    {
        $user = $order->patient?->user;
        if ($user && hospital()?->hasModule('portal')) {
            $user->notify(new HmsNotification('Lab report ready', "Your report {$order->order_no} is ready to download.", route('portal.lab-reports'), 'ri-flask-line', 'success'));
        }
        $doctorUser = $order->doctor?->user;
        if ($doctorUser) {
            $doctorUser->notify(new HmsNotification('Lab results approved', "{$order->patient->full_name} – {$order->order_no}", route('tenant.lab.order', $order), 'ri-flask-line', 'info'));
        }
    }
}
