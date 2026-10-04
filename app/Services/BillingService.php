<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DoctorCommission;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Staff;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Integrated billing for OPD, IPD, pharmacy, lab, radiology, OT and services.
 */
class BillingService
{
    /**
     * @param  array<int, array{service_type?:string, description:string, quantity?:float, unit_price:float, discount?:float, tax_percent?:float, source?:Model|null, doctor_id?:int|null}>  $items
     */
    public function createInvoice(Patient $patient, array $items, array $attributes = []): Invoice
    {
        return DB::transaction(function () use ($patient, $items, $attributes) {
            $invoice = Invoice::create(array_merge([
                'invoice_no' => Sequence::code('invoice', 'INV'),
                'patient_id' => $patient->id,
                'invoice_date' => today()->toDateString(),
                'due_date' => today()->toDateString(),
                'tpa_id' => $patient->tpa_id,
                'status' => 'unpaid',
                'created_by' => auth()->id(),
            ], $attributes));

            foreach ($items as $item) {
                $this->addItem($invoice, $item, false);
            }

            $invoice->recalculate();

            return $invoice->fresh(['items', 'payments']);
        });
    }

    public function addItem(Invoice $invoice, array $item, bool $recalculate = true): InvoiceItem
    {
        $quantity = (float) ($item['quantity'] ?? 1);
        $price = (float) $item['unit_price'];
        $discount = (float) ($item['discount'] ?? 0);
        $taxPercent = (float) ($item['tax_percent'] ?? 0);
        $taxable = max(0, $quantity * $price - $discount);
        $tax = round($taxable * $taxPercent / 100, 2);
        $source = $item['source'] ?? null;

        $invoiceItem = $invoice->items()->create([
            'service_type' => $item['service_type'] ?? 'service',
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'description' => $item['description'],
            'quantity' => $quantity,
            'unit_price' => $price,
            'discount' => $discount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $tax,
            'total' => round($taxable + $tax, 2),
            'doctor_id' => $item['doctor_id'] ?? null,
        ]);

        $this->recordCommission($invoiceItem);

        if ($recalculate) {
            $invoice->recalculate();
        }

        return $invoiceItem;
    }

    public function removeItem(InvoiceItem $item): void
    {
        $invoice = $item->invoice;
        if ($invoice->paid_amount > 0 && $invoice->status === 'paid') {
            throw ValidationException::withMessages(['item' => 'Cannot remove items from a fully paid invoice.']);
        }
        DoctorCommission::where('invoice_item_id', $item->id)->where('status', 'pending')->delete();
        $item->delete();
        $invoice->recalculate();
    }

    public function addPayment(Invoice $invoice, float $amount, string $method = 'cash', ?string $reference = null, bool $refund = false, ?string $notes = null): Payment
    {
        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages(['amount' => 'This invoice is cancelled.']);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }
        if (! $refund && $amount - max(0, $invoice->balance) > 0.009) {
            throw ValidationException::withMessages(['amount' => 'Amount exceeds the balance due ('.money($invoice->balance).').']);
        }
        if ($refund && $amount - (float) $invoice->paid_amount > 0.009) {
            throw ValidationException::withMessages(['amount' => 'Refund exceeds the amount paid.']);
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $reference, $refund, $notes) {
            $payment = Payment::create([
                'payment_no' => Sequence::code($refund ? 'refund' : 'receipt', $refund ? 'RFD' : 'RCP'),
                'invoice_id' => $invoice->id,
                'patient_id' => $invoice->patient_id,
                'amount' => $amount,
                'method' => $method,
                'reference' => $reference,
                'paid_at' => now(),
                'is_refund' => $refund,
                'notes' => $notes,
                'received_by' => auth()->id(),
            ]);
            $invoice->recalculate();

            return $payment;
        });
    }

    public function cancel(Invoice $invoice, string $reason): void
    {
        if ($invoice->paid_amount > 0) {
            throw ValidationException::withMessages(['reason' => 'Refund the payments before cancelling this invoice.']);
        }
        DB::transaction(function () use ($invoice, $reason) {
            $invoice->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);
            DoctorCommission::whereIn('invoice_item_id', $invoice->items()->pluck('id'))->where('status', 'pending')->delete();
            AuditLog::record('invoice_cancelled', $invoice, [], ['reason' => $reason]);
        });
    }

    /** Doctor fee split: commission on items attributed to a doctor. */
    protected function recordCommission(InvoiceItem $item): void
    {
        if (! $item->doctor_id) {
            return;
        }
        $doctor = Staff::find($item->doctor_id);
        if (! $doctor || (float) $doctor->commission_percent <= 0) {
            return;
        }

        DoctorCommission::create([
            'staff_id' => $doctor->id,
            'invoice_item_id' => $item->id,
            'description' => $item->description,
            'base_amount' => $item->total,
            'percent' => $doctor->commission_percent,
            'amount' => round((float) $item->total * (float) $doctor->commission_percent / 100, 2),
            'earned_at' => today()->toDateString(),
        ]);
    }

    public function defaultTaxPercent(): float
    {
        return (float) (hospital()?->tax_rate ?? 0);
    }
}
