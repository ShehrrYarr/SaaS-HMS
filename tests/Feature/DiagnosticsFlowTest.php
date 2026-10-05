<?php

namespace Tests\Feature;

use App\Models\LabTest;
use App\Models\LabTestParameter;
use App\Models\Patient;
use App\Models\RadiologyTest;
use App\Services\BillingService;
use App\Services\DiagnosticsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DiagnosticsFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsTenantUser('admin@cityhospital.test');
    }

    public function test_numeric_results_must_be_plain_numbers_and_junk_is_never_flagged_normal(): void
    {
        $hb = LabTestParameter::where('name', 'Hemoglobin')->firstOrFail();
        $this->assertNull($hb->flagFor('6,5', 'male'));
        $this->assertSame('critical_low', $hb->flagFor('6.5', 'male'));
        $this->assertSame('high', $hb->flagFor('>18', 'male'));

        $order = app(DiagnosticsService::class)->orderLab(Patient::firstOrFail(), [$hb->lab_test_id]);
        $item = $order->items()->firstOrFail();
        app(DiagnosticsService::class)->collectSample($item);

        Volt::test('tenant.lab.order', ['labOrder' => $order])->call('edit', $item->id)
            ->set("values.{$hb->id}", '6,5')->call('saveResults', true)
            ->assertHasErrors("values.{$hb->id}");
        $this->assertSame('collected', $item->fresh()->status);
    }

    public function test_cancelling_a_test_takes_it_off_an_unpaid_bill_but_not_a_paid_one(): void
    {
        $d = app(DiagnosticsService::class);
        [$a, $b] = LabTest::where('is_active', true)->take(2)->get();

        $order = $d->orderLab(Patient::firstOrFail(), [$a->id, $b->id]);
        $this->assertNull($d->unbill($order, 'Lab: '.$a->name));
        $this->assertSame(1, $order->invoice->items()->count());
        $this->assertNull($d->unbill($order, 'Lab: '.$b->name));
        $this->assertSame('cancelled', $order->invoice->fresh()->status);

        $paid = $d->orderLab(Patient::firstOrFail(), [$a->id]);
        app(BillingService::class)->addPayment($paid->invoice, $paid->invoice->balance);
        $this->assertStringContainsString('refund', $d->unbill($paid, 'Lab: '.$a->name));
        $this->assertSame('paid', $paid->invoice->fresh()->status);
    }

    public function test_images_upload_and_a_released_imaging_report_cannot_be_changed(): void
    {
        Storage::fake('local');
        $order = app(DiagnosticsService::class)->orderRadiology(Patient::firstOrFail(), [RadiologyTest::where('is_active', true)->value('id')])[0];

        $page = Volt::test('tenant.radiology.order', ['radiologyOrder' => $order])
            ->set('files', [UploadedFile::fake()->image('chest.png')])->call('uploadFiles')->assertHasNoErrors()
            ->set('findings', 'Normal study.')->set('impression', 'No abnormality.')->call('saveReport', true);
        $this->assertSame(1, $order->attachments()->count());
        $this->assertSame('approved', $order->fresh()->status);

        $page->set('impression', 'Changed later')->call('saveReport', false);
        $this->assertSame('No abnormality.', $order->fresh()->impression);
        $this->assertSame('approved', $order->fresh()->status);
    }
}
