<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\Prescription;
use App\Models\PurchaseOrder;
use App\Models\Staff;
use App\Models\Supplier;
use App\Services\PharmacyService;
use App\Support\Sequence;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PharmacyFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    protected function inStock(string $name): Medicine
    {
        return Medicine::withStock()->where('name', 'like', $name.'%')->firstOrFail();
    }

    public function test_the_counter_must_confirm_an_allergy_warning_before_selling(): void
    {
        $patient = Patient::firstOrFail();
        $patient->allergies()->create(['allergen' => 'Penicillin', 'type' => 'drug', 'severity' => 'severe']);
        $augmentin = $this->inStock('Augmentin');
        $sales = PharmacySale::count();

        $pos = Volt::test('tenant.pharmacy.pos')->set('patient_id', (string) $patient->id)->call('add', $augmentin->id)
            ->call('checkout')->assertHasErrors('allergy');
        $this->assertSame($sales, PharmacySale::count());

        $pos->set('allergyReviewed', true)->call('checkout')->assertHasNoErrors();
        $this->assertSame($sales + 1, PharmacySale::count());
        $this->assertTrue(AuditLog::where('event', 'allergy_override')->exists());
    }

    public function test_returning_a_dispensed_sale_gives_the_quantity_back_to_the_prescription(): void
    {
        $medicine = $this->inStock('Panadol');
        $rx = Prescription::create(['prescription_no' => Sequence::code('prescription', 'RX'), 'patient_id' => Patient::value('id'), 'doctor_id' => Staff::doctors()->value('id'), 'status' => 'issued']);
        $item = $rx->items()->create(['medicine_id' => $medicine->id, 'medicine_name' => $medicine->name, 'frequency' => '1-0-1', 'quantity' => 10]);

        $sale = app(PharmacyService::class)->sell([['medicine_id' => $medicine->id, 'quantity' => 4]], ['patient_id' => $rx->patient_id, 'prescription_id' => $rx->id]);
        $this->assertSame(4, (int) $item->fresh()->dispensed_qty);
        $this->assertSame('partially_dispensed', $rx->fresh()->status);

        Volt::test('tenant.pharmacy.sales')->call('view', $sale->id)->call('returnSale', $sale->id);

        $this->assertSame('returned', $sale->fresh()->status);
        $this->assertSame(0, (int) $item->fresh()->dispensed_qty);
        $this->assertSame('issued', $rx->fresh()->status);
    }

    public function test_goods_are_received_without_a_manufacturing_date(): void
    {
        $medicine = $this->inStock('Panadol');
        $po = PurchaseOrder::create(['po_no' => Sequence::code('purchase-order', 'PO'), 'supplier_id' => Supplier::value('id'), 'order_date' => today(), 'status' => 'ordered']);
        $line = $po->items()->create(['medicine_id' => $medicine->id, 'quantity' => 20, 'unit_price' => 20]);

        Volt::test('tenant.pharmacy.purchase-order', ['purchaseOrder' => $po])
            ->set("received.{$line->id}.batch_no", 'T-001')->set("received.{$line->id}.expiry_date", today()->addYear()->toDateString())
            ->set("received.{$line->id}.mfg_date", '')->set("received.{$line->id}.sale_price", '')
            ->call('receive')->assertHasNoErrors();

        $batch = $po->fresh()->items->first()->medicine->batches()->where('batch_no', 'T-001')->firstOrFail();
        $this->assertNull($batch->mfg_date);
        $this->assertEquals((float) $medicine->sale_price, (float) $batch->sale_price);
        $this->assertSame('received', $po->fresh()->status);
    }
}
