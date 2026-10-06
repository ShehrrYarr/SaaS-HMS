<?php

namespace Tests\Feature;

use App\Models\BloodBag;
use App\Models\BloodRequest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\PurchaseOrder;
use App\Models\RadiologyOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** Regression tests for the security review of 6 Oct 2026: each one tampers with a request the way an attacker would. */
class SecurityTest extends TestCase
{
    protected function stockedMedicine(bool $rx): Medicine
    {
        return Medicine::withStock()->where('is_active', true)->where('requires_prescription', $rx)->get()->firstOrFail(fn ($m) => $m->stock > 2);
    }

    public function test_pos_ignores_a_tampered_price_and_prescription_flag(): void
    {
        $this->actingAsTenantUser('pharmacist@cityhospital.test');
        $otc = $this->stockedMedicine(false);
        $real = rupees($otc->sellableBatches()->value('sale_price') ?: $otc->sale_price);

        Volt::test('tenant.pharmacy.pos')->call('add', $otc->id)->set("cart.{$otc->id}.price", 1)->set("cart.{$otc->id}.tax", 0)->call('checkout')->assertHasNoErrors();
        $sale = PharmacySale::latest('id')->firstOrFail();
        $this->assertSame($real, (int) $sale->items->first()->unit_price);

        $rx = $this->stockedMedicine(true);
        Volt::test('tenant.pharmacy.pos')->call('add', $rx->id)->set("cart.{$rx->id}.rx", false)->call('checkout')->assertHasErrors('cart');
    }

    public function test_extra_form_fields_cannot_set_status_on_new_records(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');
        $patient = Patient::firstOrFail();

        Volt::test('tenant.bloodbank.requests')->call('create')
            ->set('form.patient_id', $patient->id)->set('form.blood_group', 'O+')->set('form.units', 1)
            ->set('form.status', 'issued')->set('form.request_no', 'FAKE-1')->call('save')->assertHasNoErrors();
        $request = BloodRequest::latest('id')->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertNotSame('FAKE-1', $request->request_no);

        $medicine = Medicine::firstOrFail();
        Volt::test('tenant.pharmacy.purchase-orders')->call('create')
            ->set('form.supplier_id', Supplier::firstOrFail()->id)->set('form.status', 'received')
            ->set('lines', [['medicine_id' => $medicine->id, 'quantity' => 5, 'unit_price' => 10, 'tax_percent' => 0]])
            ->call('save')->assertHasNoErrors();
        $this->assertSame('draft', PurchaseOrder::latest('id')->firstOrFail()->status);
    }

    public function test_blood_cannot_be_released_without_all_five_screening_results(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');
        $bag = BloodBag::firstOrFail();
        $bag->update(['status' => 'quarantine', 'screening' => null]);

        Volt::test('tenant.bloodbank.inventory')->call('openScreening', $bag->id)->set('screening', [])->call('saveScreening')->assertHasErrors();
        $this->assertSame('quarantine', $bag->fresh()->status);

        $bag->update(['status' => 'issued']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Volt::test('tenant.bloodbank.inventory')->call('openScreening', $bag->id);
    }

    public function test_stat_card_hints_are_escaped(): void
    {
        $html = Blade::render('<x-stat-card title="T" value="1" :hint="$hint" />', ['hint' => '<img src=x onerror=alert(1)>']);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_only_hospital_admins_can_create_or_edit_admins(): void
    {
        $this->actingAsTenantUser('hr@cityhospital.test');
        Volt::test('tenant.hr.staff')->call('create')->set('form.name', 'Mallory')->set('form.staff_type', 'admin')
            ->set('createLogin', true)->set('form.email', 'mallory@x.test')->set('loginPassword', 'mallory-pass-1')->set('loginRole', 'Hospital Admin')
            ->call('save')->assertHasErrors('loginRole');
        $this->assertNull(User::where('email', 'mallory@x.test')->first());

        // A non-admin who was given "Manage user accounts" cannot promote anyone to admin.
        $hr = auth()->user();
        \Spatie\Permission\Models\Role::findByName('HR Manager')->givePermissionTo('users.manage');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        Volt::test('tenant.settings.users')->call('edit', $hr->id)->set('form.roles', ['HR Manager', 'Hospital Admin'])->call('save')->assertHasErrors('form.roles');
        $this->assertFalse($hr->fresh()->hasRole('Hospital Admin'));
    }

    public function test_radiology_uploads_reject_html_and_the_file_route_never_renders_it(): void
    {
        Storage::fake('local');
        $this->actingAsTenantUser('admin@cityhospital.test');
        $order = RadiologyOrder::firstOrFail();
        Volt::test('tenant.radiology.order', ['radiologyOrder' => $order])
            ->set('files', [UploadedFile::fake()->createWithContent('scan.html', '<script>alert(1)</script>')])
            ->call('uploadFiles')->assertHasErrors('files.0');

        $hid = $this->hospital()->id;
        Storage::disk('local')->put("hospitals/{$hid}/patients/1/page.html", '<script>alert(1)</script>');
        Storage::disk('local')->put("hospitals/{$hid}/certificates/lab.p12", 'secret');
        $response = $this->get("/files/hospitals/{$hid}/patients/1/page.html")->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->get("/files/hospitals/{$hid}/certificates/lab.p12")->assertForbidden();
    }

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/h/city-hospital/login')->assertOk();
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Referrer-Policy'));
    }

    public function test_one_ip_cannot_spray_passwords_across_accounts(): void
    {
        tenancy()->set($this->hospital());
        foreach (range(1, LoginThrottle::MAX_FAILURES) as $i) {
            LoginThrottle::failed();
        }
        Volt::test('auth.tenant-login')->set('email', 'admin@cityhospital.test')->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_csv_cells_cannot_carry_formulas(): void
    {
        $this->assertSame(["'=HYPERLINK(\"x\")", "'@SUM(1)", "'+92 300", '-5', -5, 'Ali'], csv_safe(['=HYPERLINK("x")', '@SUM(1)', '+92 300', '-5', -5, 'Ali']));
    }

    public function test_hospital_settings_reject_unvalidated_keys_and_script_urls(): void
    {
        $this->actingAsTenantUser('admin@sunrise.test', 'sunrise-clinic'); // the demo hospital's profile is locked
        $key = hospital()->setting('queue_display_key');

        Volt::test('tenant.settings.profile')->set('settings.pacs_viewer_url', 'javascript://x%0Aalert(1)')->call('save')->assertHasErrors('settings.pacs_viewer_url');
        Volt::test('tenant.settings.profile')->set('settings.queue_display_key', 'attacker-key')->call('save');
        $this->assertSame($key, $this->hospital('sunrise-clinic')->fresh()->setting('queue_display_key'));
    }
}
