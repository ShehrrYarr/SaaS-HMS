<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\IpdAdmission;
use App\Models\LabOrder;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Payroll;
use App\Models\PharmacySale;
use App\Models\Prescription;
use App\Models\PurchaseOrder;
use App\Models\RadiologyOrder;
use App\Models\SubscriptionInvoice;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Renders every GET screen / document of every area to catch runtime errors.
 */
class PageSmokeTest extends TestCase
{
    protected function params(): array
    {
        tenancy()->set($this->hospital());
        app(PayrollService::class)->generate(now()->format('Y-m'));
        $po = PurchaseOrder::first() ?? PurchaseOrder::create(['po_no' => 'PO-T-1', 'supplier_id' => Supplier::value('id'), 'order_date' => today(), 'status' => 'ordered']);

        return [
            'patient' => Patient::first()->id,
            'patientId' => Patient::first()->id,
            'visit' => OpdVisit::first()->id,
            'visitId' => OpdVisit::first()->id,
            'admission' => IpdAdmission::where('status', 'admitted')->first()->id,
            'admissionId' => IpdAdmission::where('status', 'discharged')->first()->id,
            'purchaseOrder' => $po->id,
            'saleId' => PharmacySale::first()->id,
            'labOrder' => LabOrder::first()->id,
            'orderId' => LabOrder::where('status', 'approved')->first()->id,
            'radiologyOrder' => RadiologyOrder::first()->id,
            'surgery' => Surgery::first()->id,
            'appointment' => Appointment::where('mode', 'video')->first()->id,
            'invoice' => Invoice::first()->id,
            'invoiceId' => Invoice::first()->id,
            'prescriptionId' => Prescription::first()->id,
            'payrollId' => Payroll::first()->id,
        ];
    }

    protected function tenantRoutes(string $prefix): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods()) && str_starts_with((string) $r->getName(), $prefix))
            ->reject(fn ($r) => str_ends_with($r->getName(), '.login') || in_array($r->getName(), ['tenant.queue.display']))
            ->all();
    }

    public function test_every_hospital_screen_and_document_renders_for_the_hospital_admin(): void
    {
        $params = $this->params();
        $this->actingAsTenantUser('admin@cityhospital.test');
        $failures = [];

        foreach ($this->tenantRoutes('tenant.') as $route) {
            // Same placeholder name means different records on some routes.
            $overrides = ['tenant.radiology.report' => ['orderId' => RadiologyOrder::where('status', 'approved')->value('id')]];
            $values = ($overrides[$route->getName()] ?? []) + $params;
            $uri = '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'hospital' ? 'city-hospital' : ($values[$m[1]] ?? 'missing'), $route->uri());
            $status = $this->get($uri)->getStatusCode();
            if ($status >= 400 || $status === 0) {
                $failures[] = "{$status} {$uri} ({$route->getName()})";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_every_super_admin_screen_renders(): void
    {
        $this->actingAs($this->userByEmail(config('hms.super_admin_email')));
        $invoice = SubscriptionInvoice::withoutGlobalScopes()->first() ?? tenancy()->withoutScope(fn () => app(\App\Services\SubscriptionService::class)->generateInvoice($this->hospital()));
        $failures = [];

        foreach ($this->tenantRoutes('admin.') as $route) {
            $uri = '/'.str_replace(['{hospital}', '{invoiceId}'], ['city-hospital', $invoice->id], $route->uri());
            $status = $this->get($uri)->getStatusCode();
            if ($status >= 400) {
                $failures[] = "{$status} {$uri}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_every_portal_screen_renders_for_the_patient(): void
    {
        $this->actingAsTenantUser('patient@cityhospital.test');
        $patient = auth()->user()->patient;
        $failures = [];
        $order = LabOrder::where('patient_id', $patient->id)->where('status', 'approved')->first();
        $rx = Prescription::where('patient_id', $patient->id)->first();
        $inv = Invoice::where('patient_id', $patient->id)->first();
        $video = Appointment::where('patient_id', $patient->id)->where('mode', 'video')->first();

        foreach ($this->tenantRoutes('portal.') as $route) {
            $uri = '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => match ($m[1]) {
                'hospital' => 'city-hospital', 'orderId' => $order?->id ?? 'skip', 'prescriptionId' => $rx?->id ?? 'skip',
                'invoiceId' => $inv?->id ?? 'skip', 'appointment' => $video?->id ?? 'skip', default => 'skip',
            }, $route->uri());
            if (str_contains($uri, 'skip') || $route->getName() === 'portal.video') {
                continue; // patient has no such record in the demo data / video page needs today's date
            }
            $status = $this->get($uri)->getStatusCode();
            if ($status >= 400) {
                $failures[] = "{$status} {$uri}";
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_each_default_role_can_open_its_dashboard(): void
    {
        foreach (['doctor', 'nurse', 'reception', 'pharmacist', 'lab', 'radiology', 'accounts', 'hr'] as $who) {
            $this->actingAsTenantUser("{$who}@cityhospital.test");
            $this->get($this->tenantUrl('dashboard'))->assertOk();
        }
    }

    public function test_public_pages_render(): void
    {
        $this->get('/')->assertOk();
        $this->get('/admin/login')->assertOk();
        $this->get('/h/city-hospital/login')->assertOk();
        $this->get('/h/city-hospital/portal/login')->assertOk();
        $this->get('/template/index')->assertOk();
        $key = $this->hospital()->setting('queue_display_key');
        $this->get("/h/city-hospital/queue-display?key={$key}")->assertOk();
        $this->get('/h/city-hospital/queue-display?key=wrong')->assertForbidden();
        $this->get('/h/does-not-exist/login')->assertNotFound();
    }
}
