<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\LabTest;
use App\Models\LabTestParameter;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\BillingService;
use App\Services\BloodBankService;
use App\Services\DiagnosticsService;
use App\Services\IpdService;
use App\Services\OpdService;
use App\Services\PharmacyService;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    public function test_quick_registration_generates_uhid_and_opd_token(): void
    {
        $doctor = Staff::doctors()->first();

        Volt::test('tenant.patients.index')
            ->call('openQuick')
            ->set('quick.first_name', 'Walk')->set('quick.last_name', 'In')->set('quick.age', 40)->set('quick.gender', 'male')
            ->set('opdDoctor', (string) $doctor->id)
            ->call('saveQuick')
            ->assertHasNoErrors();

        $patient = Patient::where('first_name', 'Walk')->firstOrFail();
        $this->assertMatchesRegularExpression('/^CGH-\d{2}-\d{6}$/', $patient->uhid);
        $this->assertSame(1, $patient->opdVisits()->count());
        $this->assertNotNull($patient->opdVisits()->first()->token_no);
    }

    public function test_check_in_creates_visit_invoice_and_doctor_commission(): void
    {
        $appointment = Appointment::where('status', 'booked')->firstOrFail();
        $visit = app(OpdService::class)->checkIn($appointment);

        $this->assertSame('checked_in', $appointment->fresh()->status);
        $this->assertNotNull($visit->invoice_id);
        $commission = \App\Models\DoctorCommission::where('staff_id', $visit->doctor_id)->latest('id')->first();
        $this->assertEquals(round((float) $visit->invoice->items->first()->total * $visit->doctor->commission_percent / 100, 2), (float) $commission->amount);
    }

    public function test_pharmacy_sale_deducts_stock_first_expiry_first_out(): void
    {
        $medicine = Medicine::create(['name' => 'TestMed 1mg', 'form' => 'tablet', 'unit' => 'strip', 'purchase_price' => 1, 'sale_price' => 2, 'reorder_level' => 1]);
        $late = MedicineBatch::create(['medicine_id' => $medicine->id, 'batch_no' => 'LATE', 'expiry_date' => now()->addYear(), 'quantity_received' => 10, 'quantity_available' => 10, 'sale_price' => 2]);
        $soon = MedicineBatch::create(['medicine_id' => $medicine->id, 'batch_no' => 'SOON', 'expiry_date' => now()->addMonth(), 'quantity_received' => 5, 'quantity_available' => 5, 'sale_price' => 2]);
        MedicineBatch::create(['medicine_id' => $medicine->id, 'batch_no' => 'EXPIRED', 'expiry_date' => now()->subDay(), 'quantity_received' => 50, 'quantity_available' => 50, 'sale_price' => 2]);

        $sale = app(PharmacyService::class)->sell([['medicine_id' => $medicine->id, 'quantity' => 7]], ['payment_method' => 'cash']);

        $this->assertSame(0, $soon->fresh()->quantity_available);
        $this->assertSame(8, $late->fresh()->quantity_available);
        $this->assertEquals(14.00, (float) $sale->total);

        $this->expectException(ValidationException::class);
        app(PharmacyService::class)->sell([['medicine_id' => $medicine->id, 'quantity' => 9]]); // only 8 sellable (expired excluded)
    }

    public function test_lab_results_are_flagged_against_gender_specific_ranges(): void
    {
        $hb = LabTestParameter::where('code', 'HGB')->firstOrFail();

        $this->assertSame('low', $hb->flagFor('12.5', 'male'));
        $this->assertSame('normal', $hb->flagFor('12.5', 'female'));
        $this->assertSame('critical_low', $hb->flagFor('6', 'female'));
        $this->assertSame('high', $hb->flagFor('17.5', 'female'));
    }

    public function test_lab_order_moves_through_collection_results_and_approval(): void
    {
        $patient = Patient::firstOrFail();
        $diag = app(DiagnosticsService::class);
        $order = $diag->orderLab($patient, [LabTest::where('code', 'FBS')->value('id')]);
        $item = $order->items()->first();

        $diag->collectSample($item);
        $this->assertNotNull($item->fresh()->sample_barcode);

        $diag->saveResults($item->fresh(), [$item->test->parameters->first()->id => '250']);
        $this->assertSame('high', $item->results()->first()->flag);

        $diag->approve($item->fresh());
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->verification_code);
        $this->get('/verify/lab/'.$order->fresh()->verification_code)->assertOk()->assertSee('Authentic');
    }

    public function test_admission_transfer_and_discharge_produce_a_consolidated_bill(): void
    {
        $ipd = app(IpdService::class);
        $patient = Patient::whereDoesntHave('admissions', fn ($q) => $q->where('status', 'admitted'))->firstOrFail();
        [$bedA, $bedB] = Bed::where('status', 'available')->take(2)->get();

        $admission = $ipd->admit($patient, ['doctor_id' => Staff::doctors()->value('id'), 'bed_id' => $bedA->id, 'deposit_amount' => 50]);
        $this->assertSame('occupied', $bedA->fresh()->status);

        $ipd->transfer($admission, $bedB->id);
        $this->assertSame('cleaning', $bedA->fresh()->status);

        $ipd->addCharge($admission->fresh(), ['category' => 'procedure', 'description' => 'Dressing', 'unit_price' => 10]);
        $invoice = $ipd->discharge($admission->fresh(), ['discharge_summary' => 'Recovered']);

        $this->assertSame('discharged', $admission->fresh()->status);
        $this->assertCount(3, $invoice->items); // two bed allocations + one charge
        $this->assertEquals(50.0, (float) $invoice->paid_amount);
    }

    public function test_invoice_totals_and_status_follow_payments(): void
    {
        $billing = app(BillingService::class);
        $invoice = $billing->createInvoice(Patient::firstOrFail(), [
            ['description' => 'Service A', 'quantity' => 2, 'unit_price' => 50, 'tax_percent' => 10],
            ['description' => 'Service B', 'unit_price' => 20, 'discount' => 5],
        ]);

        $this->assertEquals(125.00, (float) $invoice->total); // 100 + 10 tax + 15
        $billing->addPayment($invoice, 25);
        $this->assertSame('partial', $invoice->fresh()->status);
        $billing->addPayment($invoice->fresh(), 100);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->expectException(ValidationException::class);
        $billing->addPayment($invoice->fresh(), 1);
    }

    public function test_blood_compatibility_rules(): void
    {
        $this->assertSame(['O-'], BloodBankService::compatibleDonorGroups('O-', 'prbc'));
        $this->assertContains('O-', BloodBankService::compatibleDonorGroups('AB+', 'prbc'));
        $this->assertNotContains('A+', BloodBankService::compatibleDonorGroups('O+', 'prbc'));
        // Plasma is the reverse: AB plasma is universal.
        $this->assertContains('AB-', BloodBankService::compatibleDonorGroups('O+', 'ffp'));
        $this->assertNotContains('O+', BloodBankService::compatibleDonorGroups('AB+', 'ffp'));
    }

    public function test_lab_device_api_ingests_results_by_barcode(): void
    {
        $diag = app(DiagnosticsService::class);
        $order = $diag->orderLab(Patient::firstOrFail(), [LabTest::where('code', 'FBS')->value('id')]);
        $item = $diag->collectSample($order->items()->first())->fresh();
        $device = \App\Models\LabDevice::firstOrFail();
        tenancy()->forget();

        $this->postJson('/api/lab-devices/results', ['barcode' => $item->sample_barcode, 'results' => ['GLU' => 95]], ['Authorization' => 'Bearer '.$device->api_token])
            ->assertOk()->assertJsonPath('flags.GLU', 'normal');
        $this->postJson('/api/lab-devices/results', ['barcode' => 'X', 'results' => ['GLU' => 1]], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }
}
