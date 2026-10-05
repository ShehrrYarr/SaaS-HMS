<?php

namespace App\Services;

use App\Models\IpdAdmission;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\Prescription;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Support\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pharmacy stock & POS. Stock is always deducted FEFO (first-expiry-first-out)
 * from non-expired batches, and every change is written to stock_movements.
 */
class PharmacyService
{
    public function __construct(protected BillingService $billing, protected IpdService $ipd, protected LedgerService $ledger) {}

    /**
     * Paid sales go into $data['bank_account_id'] (default: Cash); payment_method 'ipd_credit' instead
     * posts the sale to the admitted patient's IPD bill.
     *
     * @param  array<int, array{medicine_id:int, quantity:int, unit_price?:int, discount?:int}>  $lines
     * @param  array{patient_id?:int|null, customer_name?:string|null, customer_phone?:string|null, prescription_id?:int|null, ipd_admission_id?:int|null, payment_method?:string|null, bank_account_id?:int|string|null, discount?:int, notes?:string|null}  $data
     */
    public function sell(array $lines, array $data = []): PharmacySale
    {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['cart' => 'The cart is empty.']);
        }

        $isCredit = ($data['payment_method'] ?? null) === 'ipd_credit';
        $account = $isCredit ? null : $this->ledger->account($data['bank_account_id'] ?? 'cash');

        return DB::transaction(function () use ($lines, $data, $isCredit, $account) {
            $sale = PharmacySale::create([
                'sale_no' => Sequence::code('pharmacy-sale', 'PH'),
                'patient_id' => $data['patient_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'prescription_id' => $data['prescription_id'] ?? null,
                'ipd_admission_id' => $data['ipd_admission_id'] ?? null,
                'payment_method' => $isCredit ? 'ipd_credit' : $account->type,
                'bank_account_id' => $account?->id,
                'notes' => $data['notes'] ?? null,
                'sold_by' => auth()->id(),
            ]);

            $subtotal = 0;
            $tax = 0;
            $lineDiscounts = 0;
            foreach ($lines as $line) {
                $medicine = Medicine::findOrFail($line['medicine_id']);
                $remaining = (int) $line['quantity'];
                $price = isset($line['unit_price']) ? rupees($line['unit_price']) : null;
                $lineDiscount = rupees($line['discount'] ?? 0);

                $batches = $medicine->sellableBatches()->lockForUpdate()->get();
                if ($batches->sum('quantity_available') < $remaining) {
                    throw ValidationException::withMessages(['cart' => "Insufficient stock for {$medicine->name} (available: {$batches->sum('quantity_available')})."]);
                }

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min($remaining, $batch->quantity_available);
                    $unit = $price ?? ($batch->sale_price > 0 ? $batch->sale_price : $medicine->sale_price);
                    $gross = $take * $unit;
                    $share = $line['quantity'] > 0 ? rupees($lineDiscount * $take / $line['quantity']) : 0;
                    $lineTax = rupees(($gross - $share) * (float) $medicine->tax_percent / 100);

                    $sale->items()->create([
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $batch->id,
                        'quantity' => $take,
                        'unit_price' => $unit,
                        'tax_percent' => $medicine->tax_percent,
                        'discount' => $share,
                        'total' => $gross - $share + $lineTax,
                    ]);

                    $this->move($batch, -$take, 'sale', $sale, "Sale {$sale->sale_no}");
                    $subtotal += $gross;
                    $tax += $lineTax;
                    $lineDiscounts += $share;
                    $remaining -= $take;
                }
            }

            $discount = min($subtotal, $lineDiscounts + rupees($data['discount'] ?? 0));
            $total = $subtotal - $discount + $tax;
            $sale->update([
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'paid_amount' => $isCredit ? 0 : $total,
            ]);

            if ($sale->prescription_id) {
                $this->applyToPrescription($sale);
            }

            // Name the medicines on the patient's bill, e.g. "Pharmacy sale PH-26-00011: Ceftriaxone 1g Inj × 2".
            $summary = Str::limit("Pharmacy sale {$sale->sale_no}: ".$sale->items()->with('medicine')->get()->groupBy('medicine_id')
                ->map(fn ($items) => $items->first()->medicine->name.' × '.$items->sum('quantity'))->implode(', '), 250);

            if ($isCredit) {
                $admission = IpdAdmission::findOrFail($sale->ipd_admission_id);
                $this->ipd->addCharge($admission, [
                    'category' => 'medicine', 'description' => $summary, 'unit_price' => $total, 'source' => $sale,
                ]);
            } elseif ($sale->patient_id && hospital()?->hasModule('billing')) {
                // Mirror into the patient's unified bill as a settled invoice (the receipt posts to the account).
                $invoice = $this->billing->createInvoice(Patient::findOrFail($sale->patient_id), [[
                    'service_type' => 'pharmacy', 'description' => $summary, 'unit_price' => $total, 'source' => $sale,
                ]]);
                if ($invoice->balance > 0) {
                    $this->billing->addPayment($invoice, $invoice->balance, $account, $sale->sale_no);
                }
            } elseif ($total > 0) {
                $this->ledger->moneyIn($account, $total, 'pharmacy', $sale, "Pharmacy sale {$sale->sale_no} · ".($sale->customer_name ?: 'Walk-in'));
            }

            return $sale->fresh(['items.medicine', 'items.batch']);
        });
    }

    /** Receive goods against a purchase order: creates batches and stock-in movements. */
    public function receive(PurchaseOrder $order, array $received): void
    {
        DB::transaction(function () use ($order, $received) {
            foreach ($order->items as $item) {
                $row = $received[$item->id] ?? null;
                $qty = (int) ($row['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                if (empty($row['batch_no']) || empty($row['expiry_date'])) {
                    throw ValidationException::withMessages(["received.{$item->id}.batch_no" => 'Batch number and expiry are required for received items.']);
                }

                $batch = MedicineBatch::create([
                    'medicine_id' => $item->medicine_id,
                    'supplier_id' => $order->supplier_id,
                    'purchase_order_id' => $order->id,
                    'batch_no' => $row['batch_no'],
                    // Blank form fields arrive as '' and MySQL rejects '' for dates and amounts.
                    'mfg_date' => ($row['mfg_date'] ?? '') ?: null,
                    'expiry_date' => $row['expiry_date'],
                    'quantity_received' => $qty,
                    'quantity_available' => 0,
                    'purchase_price' => $item->unit_price,
                    'sale_price' => ($row['sale_price'] ?? '') !== '' ? $row['sale_price'] : $item->medicine->sale_price,
                ]);
                $this->move($batch, $qty, 'purchase', $order, "PO {$order->po_no}");
                $item->increment('received_qty', $qty);
            }

            $order->refresh()->load('items');
            $complete = $order->items->every(fn ($i) => $i->received_qty >= $i->quantity);
            $order->update([
                'status' => $complete ? 'received' : 'partially_received',
                'received_at' => $complete ? now() : $order->received_at,
            ]);
        });
    }

    public function adjust(MedicineBatch $batch, int $delta, string $type, string $note): void
    {
        if ($batch->quantity_available + $delta < 0) {
            throw ValidationException::withMessages(['quantity' => 'Adjustment would make stock negative.']);
        }
        DB::transaction(fn () => $this->move($batch, $delta, $type, null, $note));
    }

    public function move(MedicineBatch $batch, int $delta, string $type, ?Model $reference = null, ?string $note = null): void
    {
        $batch->quantity_available += $delta;
        $batch->save();

        StockMovement::create([
            'medicine_id' => $batch->medicine_id,
            'medicine_batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => (int) MedicineBatch::where('medicine_id', $batch->medicine_id)->sum('quantity_available'),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'user_id' => auth()->id(),
        ]);
    }

    /** A returned sale gives its quantities back to the prescription it dispensed. */
    public function unapplyFromPrescription(PharmacySale $sale): void
    {
        $prescription = Prescription::with('items')->find($sale->prescription_id);
        if (! $prescription) {
            return;
        }
        $returned = $sale->items->groupBy('medicine_id')->map->sum('quantity');
        foreach ($prescription->items as $item) {
            if ($item->medicine_id && ($qty = $returned[$item->medicine_id] ?? 0) > 0) {
                $back = min($qty, (int) $item->dispensed_qty);
                $item->decrement('dispensed_qty', $back);
                $returned[$item->medicine_id] = $qty - $back;
            }
        }
        $prescription->refresh()->load('items');
        $linked = $prescription->items->filter(fn ($i) => $i->medicine_id);
        $status = match (true) {
            $linked->every(fn ($i) => $i->dispensed_qty >= $i->quantity) => 'dispensed',
            $linked->every(fn ($i) => (int) $i->dispensed_qty === 0) => 'issued',
            default => 'partially_dispensed',
        };
        $prescription->update(['status' => $status, 'dispensed_at' => $status === 'issued' ? null : $prescription->dispensed_at]);
    }

    protected function applyToPrescription(PharmacySale $sale): void
    {
        $prescription = Prescription::with('items')->find($sale->prescription_id);
        if (! $prescription) {
            return;
        }
        $sold = $sale->items->groupBy('medicine_id')->map->sum('quantity');
        foreach ($prescription->items as $item) {
            if ($item->medicine_id && ($qty = $sold[$item->medicine_id] ?? 0) > 0) {
                $apply = min($qty, $item->pending_qty);
                $item->increment('dispensed_qty', $apply);
                $sold[$item->medicine_id] = $qty - $apply;
            }
        }
        $prescription->refresh()->load('items');
        $allDone = $prescription->items->every(fn ($i) => $i->dispensed_qty >= $i->quantity || ! $i->medicine_id);
        $prescription->update([
            'status' => $allDone ? 'dispensed' : 'partially_dispensed',
            'dispensed_at' => now(),
        ]);
    }
}
