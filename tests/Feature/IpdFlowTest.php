<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Bed;
use App\Models\IpdAdmission;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\IpdService;
use Livewire\Volt\Volt;
use Tests\TestCase;

class IpdFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    public function test_admitting_a_patient_who_is_already_admitted_explains_why(): void
    {
        $admission = IpdAdmission::where('status', 'admitted')->firstOrFail();
        $bed = Bed::where('status', 'available')->firstOrFail();

        Volt::test('tenant.ipd.admit')
            ->set('form.patient_id', (string) $admission->patient_id)
            ->set('form.doctor_id', (string) Staff::doctors()->value('id'))
            ->set('form.bed_id', (string) $bed->id)
            ->call('save')
            ->assertHasErrors(['form.patient_id'])
            ->assertSee('This patient is already admitted.');

        $this->assertSame(1, IpdAdmission::where('patient_id', $admission->patient_id)->where('status', 'admitted')->count());
    }

    public function test_a_charge_without_a_doctor_is_saved(): void
    {
        $admission = IpdAdmission::where('status', 'admitted')->firstOrFail();

        Volt::test('tenant.ipd.show', ['admission' => $admission])
            ->set('charge.category', 'consumable')->set('charge.description', 'Oxygen cylinder')
            ->set('charge.quantity', '0.5')->set('charge.unit_price', '1200')
            ->call('addCharge')
            ->assertHasNoErrors();

        $charge = $admission->charges()->latest('id')->firstOrFail();
        $this->assertNull($charge->doctor_id);
        $this->assertEquals(600, (float) $charge->amount);
    }

    public function test_the_unused_deposit_is_refunded_once_after_discharge(): void
    {
        $ipd = app(IpdService::class);
        $patient = Patient::whereDoesntHave('admissions', fn ($q) => $q->where('status', 'admitted'))->firstOrFail();
        $admission = $ipd->admit($patient, ['doctor_id' => Staff::doctors()->value('id'), 'bed_id' => Bed::where('status', 'available')->value('id'), 'deposit_amount' => 50000]);
        $invoice = $ipd->discharge($admission->fresh(), ['discharge_summary' => 'Recovered']);

        $due = 50000 - (int) $invoice->total;
        $this->assertSame($due, $admission->fresh()->depositRefundDue());

        $page = Volt::test('tenant.ipd.show', ['admission' => $admission->fresh()])->call('refundDeposit')->assertHasNoErrors();
        $refund = BankTransaction::where('type', 'refund')->where('source_id', $admission->id)->sole();
        $this->assertSame('out', $refund->direction);
        $this->assertEquals($due, (float) $refund->amount);
        $this->assertSame(0, $admission->fresh()->depositRefundDue());

        $page->call('refundDeposit');
        $this->assertSame(1, BankTransaction::where('type', 'refund')->where('source_id', $admission->id)->count());
    }
}
