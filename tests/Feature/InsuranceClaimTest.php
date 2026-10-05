<?php

namespace Tests\Feature;

use App\Models\InsuranceClaim;
use App\Models\Patient;
use App\Models\Tpa;
use App\Services\BillingService;
use Livewire\Volt\Volt;
use Tests\TestCase;

class InsuranceClaimTest extends TestCase
{
    public function test_an_approved_claim_keeps_covering_the_bill_until_the_insurer_pays(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');
        $patient = Patient::firstOrFail();
        $patient->update(['tpa_id' => Tpa::value('id')]);
        $invoice = app(BillingService::class)->createInvoice($patient->fresh(), [['description' => 'Consultation', 'unit_price' => 3000]]);
        $invoice->update(['insurance_amount' => 2000]);
        $invoice->recalculate();

        $claims = Volt::test('tenant.billing.claims')->set('invoiceId', (string) $invoice->id)->call('createClaim');
        $claim = InsuranceClaim::where('invoice_id', $invoice->id)->sole();

        // Blank amounts while only submitting must not break the save.
        $claims->set('form.status', 'submitted')->set('form.approved_amount', '')->set('form.settled_amount', '')->call('save')->assertHasNoErrors();

        $claims->call('edit', $claim->id)->set('form.status', 'partially_approved')->set('form.approved_amount', '1800')->call('save');
        $this->assertSame(1800, $invoice->fresh()->insurance_amount);
        $this->assertSame(1200, $invoice->fresh()->balance);

        $claims->call('edit', $claim->id)->set('form.status', 'settled')->set('form.settled_amount', '1800')->call('save');
        $this->assertSame(0, $invoice->fresh()->insurance_amount);
        $this->assertEquals(1800, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(1200, $invoice->fresh()->balance);
    }
}
