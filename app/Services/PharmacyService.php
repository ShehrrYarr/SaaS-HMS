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
use Illuminate\Validation\ValidationException;

/**
 * Pharmacy stock & POS. Stock is always deducted FEFO (first-expiry-first-out)
 * from non-expired batches, and every change is written to stock_movements.
 */
class PharmacyService
{
    public function __construct(protected BillingService $billing, protected IpdService $ipd) {}

    /**
     * @param  array<int, array{medicine_id:int, quantity:int, unit_price?:float, discount?:float}>  $lines
     * @param  array{patient_id?:int|null, customer_name?:string|null, customer_phone?:string|null, prescription_id?:int|null, ipd_admission_id?:int|null, payment_method?:string, paid_amount?:float, discount?:float, notes?:string|null}  $data
     */
    public function sell(array $lines, array $data = []): PharmacySale
    {
        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
        if (! $lines) {
            throw ValidationException::withMessages(['cart' => 'The cart is empty.']);
        }

        return DB::transaction(function () use ($lines, $data) {
            $sale = PharmacySale::create([
                'sale_no' => Sequence::code('pharmacy-sale', 'PH'),
                'patient_id' => $data['patient_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'prescription_id' => $data['prescription_id'] ?? null,
                'ipd_admission_id' => $data['ipd_admission_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'sold_by' => auth()->id(),
            ]);

            $subtotal = 0;
            $tax = 0;
            $lineDiscounts = 0;
            foreach ($lines as $line) {
                $medicine = Medicine::findOrFail($line['medicine_id']);
                $remaining = (int) $line['quantity'];
                $price = isset($line['unit_price']) ? (float) $line['unit_price'] : null;
                $lineDiscount = (float) ($line['discount'] ?? 0);

                $batches = $medicine->sellableBatches()->lockForUpdate()->get();
                if ($batches->sum('quantity_available') < $remaining) {
                    throw ValidationException::withMessages(['cart' => "Insufficient stock for {$medicine->name} (available: {$batches->sum('quantity_available')})."]);
                }

                foreach ($batches as $batch) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min($remaining, $batch->quantity_available);
                    $unit = $price ?? (float) ($batch->sale_price > 0 ? $batch->sale_price : $medicine->sale_price);
                    $gross = $take * $unit;
                    $share = $line['quantity'] > 0 ? $lineDiscount * $take / $line['quantity'] : 0;
                    $lineTax = round(($gross - $share) * (float) $medicine->tax_percent / 100, 2);

                    $sale->items()->create([
                        'medicine_id' => $medicine->id,
                        'medicine_batch_id' => $batch->id,
                        'quantity' => $take,
                        'unit_price' => $unit,
                        'tax_percent' => $medicine->tax_percent,
                        'discount' => round($share, 2),
                        'total' => round($gross - $share + $lineTax, 2),
                    ]);

                    $this->move($batch, -$take, 'sale', $sale, "Sale {$sale->sale_no}");
                    $subtotal += $gross;
                    $tax += $lineTax;
                    $lineDiscounts += $share;
                    $remaining -= $take;
                }
            }

            $discount = round($lineDiscounts + (float) ($data['discount'] ?? 0), 2);
            $total = round($subtotal - $discount + $tax, 2);
            $isCredit = ($data['payment_method'] ?? 'cash') === 'ipd_credit';
            $sale->update([
                'subtotal' => round($subtotal, 2),
                'discount' => $discount,
                'tax' => round($tax, 2),
                'total' => $total,
                'paid_amount' => $isCredit ? 0 : round((float) ($data['paid_amount'] ?? $total), 2),
            ]);

            if ($sale->prescription_id) {
                $this->applyToPrescription($sale);
            }

            if ($isCredit) {
                $admission = IpdAdmission::findOrFail($sale->ipd_admission_id);
                $this->ipd->addCharge($admission, [
                    'category' => 'medicine', 'description' => "Pharmacy sale {$sale->sale_no}", 'unit_price' => $total, 'source' => $sale,
                ]);
            } elseif ($sale->patient_id && hospital()?->hasModule('billing')) {
                // Mirror into the patient's unified bill as a settled invoice.
                $invoice = $this->billing->createInvoice(Patient::findOrFail($sale->patient_id), [[
                    'service_type' => 'pharmacy', 'description' => "Pharmacy sale {$sale->sale_no}", 'unit_price' => $total, 'source' => $sale,
                ]]);
                $this->billing->addPayment($invoice, $invoice->balance, $sale->payment_method === 'ipd_credit' ? 'cash' : $sale->payment_method, $sale->sale_no);
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
                    'mfg_date' => $row['mfg_date'] ?? null,
                    'expiry_date' => $row['expiry_date'],
                    'quantity_received' => $qty,
                    'quantity_available' => 0,
                    'purchase_price' => $item->unit_price,
                    'sale_price' => $row['sale_price'] ?? $item->medicine->sale_price,
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
