<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabOrder;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\User;
use App\Services\FbrRegister;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FbrPortalTest extends TestCase
{
    public function test_fbr_and_staff_accounts_each_stay_in_their_own_area(): void
    {
        $this->actingAsTenantUser('fbr@cityhospital.test');
        $this->get($this->tenantUrl('fbr'))->assertOk()->assertSee('Patient Visits');
        $this->get($this->tenantUrl('patients'))->assertRedirect('/h/city-hospital/fbr');
        $this->get($this->tenantUrl('portal'))->assertRedirect('/h/city-hospital/dashboard');

        $this->actingAsTenantUser('admin@cityhospital.test');
        $this->get($this->tenantUrl('fbr'))->assertRedirect('/h/city-hospital/dashboard');
        $this->get($this->tenantUrl('fbr/export/csv'))->assertRedirect('/h/city-hospital/dashboard');
    }

    public function test_guests_are_sent_to_the_fbr_login_and_only_fbr_accounts_can_use_it(): void
    {
        $this->get($this->tenantUrl('fbr'))->assertRedirect('/h/city-hospital/fbr/login');
        tenancy()->set($this->hospital());

        Volt::test('auth.fbr-login')->set('email', 'admin@cityhospital.test')->set('password', 'password')->call('login')->assertHasErrors('email');
        $this->assertGuest();

        Volt::test('auth.fbr-login')->set('email', 'fbr@cityhospital.test')->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertRedirect('/h/city-hospital/fbr');
    }

    public function test_the_register_lists_visits_and_walk_ins_but_not_orders_made_during_a_visit(): void
    {
        $this->actingAsTenantUser('fbr@cityhospital.test');
        $visit = OpdVisit::where('status', '!=', 'cancelled')->firstOrFail();
        $day = $visit->visit_date->toDateString();
        $patient = Patient::firstOrFail();
        $walkIn = LabOrder::create(['order_no' => 'LAB-FBR-1', 'patient_id' => $patient->id, 'ordered_at' => $visit->visit_date, 'status' => 'ordered']);
        $duringVisit = LabOrder::create(['order_no' => 'LAB-FBR-2', 'patient_id' => $visit->patient_id, 'ordered_at' => $visit->visit_date, 'status' => 'ordered',
            'visitable_type' => 'opd_visit', 'visitable_id' => $visit->id]);
        $sale = PharmacySale::create(['sale_no' => 'PH-FBR-1', 'customer_name' => 'Counter customer', 'status' => 'completed', 'created_at' => $visit->visit_date]);

        $refs = app(FbrRegister::class)->query($day, $day)->get()->map(fn ($r) => $r->type.':'.$r->id);
        $this->assertContains('opd:'.$visit->id, $refs);
        $this->assertContains('lab:'.$walkIn->id, $refs);
        $this->assertContains('pharmacy:'.$sale->id, $refs);
        $this->assertNotContains('lab:'.$duringVisit->id, $refs);

        $this->assertNotContains('opd:'.$visit->id, app(FbrRegister::class)->query('2001-01-01', '2001-01-31')->get()->map(fn ($r) => $r->type.':'.$r->id));

        Volt::test('fbr.register')->set('from', $day)->set('to', $day)
            ->assertSee($visit->visit_no)->assertSee('LAB-FBR-1')->assertSee('Counter customer')->assertDontSee('LAB-FBR-2');
    }

    public function test_date_ranges_are_sanitised(): void
    {
        $this->assertSame(['2025-01-01', '2025-01-10'], FbrRegister::range('2025-01-10', '2025-01-01'));
        $this->assertSame(['2024-12-31', '2025-12-31'], FbrRegister::range('2024-01-01', '2025-12-31')); // trimmed to 366 days, inclusive
        $this->assertSame([today()->startOfMonth()->toDateString(), today()->toDateString()], FbrRegister::range('rubbish', null));
    }

    public function test_viewing_and_exporting_are_audit_logged(): void
    {
        $this->actingAsTenantUser('fbr@cityhospital.test');
        $day = OpdVisit::firstOrFail()->visit_date->toDateString();

        Volt::test('fbr.register')->set('from', $day);
        $this->assertTrue(AuditLog::where('event', 'fbr_viewed')->exists());

        $csv = $this->get($this->tenantUrl("fbr/export/csv?from={$day}&to={$day}"))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Visit type', $csv->streamedContent());

        $pdf = $this->get($this->tenantUrl("fbr/export/pdf?from={$day}&to={$day}"))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        $this->assertSame(2, AuditLog::where('event', 'fbr_exported')->count());
    }

    public function test_admin_creates_fbr_accounts_that_hold_only_the_fbr_role(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');

        Volt::test('tenant.settings.fbr')->call('create')
            ->set('form.name', 'Tax Officer')->set('form.email', 'officer@fbr.test')->set('form.password', 'officer-pass-1')
            ->call('save')->assertHasNoErrors();

        $officer = User::where('email', 'officer@fbr.test')->firstOrFail();
        $this->assertSame(['FBR Officer'], $officer->getRoleNames()->all());
        $this->assertTrue($officer->isFbrOfficer());

        // FBR accounts stay off the staff Users page and its role list.
        Volt::test('tenant.settings.users')->assertDontSee('officer@fbr.test');
        Volt::test('tenant.settings.users')->call('create')->set('form.name', 'Mixed')->set('form.email', 'mixed@x.test')
            ->set('form.password', 'mixed-pass-1')->set('form.roles', ['Doctor', 'FBR Officer'])->call('save')->assertHasErrors('form.roles.1');
    }

    public function test_uploads_are_limited_to_branding_for_fbr_and_to_their_own_folder_for_patients(): void
    {
        Storage::fake('local');
        $hid = $this->hospital()->id;
        $portalPatient = Patient::withoutHospitalScope()->whereNotNull('user_id')->firstOrFail();
        $other = Patient::withoutHospitalScope()->where('hospital_id', $hid)->whereKeyNot($portalPatient->id)->firstOrFail();
        foreach (['branding/logo.png', "patients/{$other->id}/report.pdf", "patients/{$portalPatient->id}/own.pdf"] as $file) {
            Storage::disk('local')->put("hospitals/{$hid}/{$file}", 'x');
        }
        $url = fn (string $file) => "/files/hospitals/{$hid}/{$file}";

        $this->actingAs($this->userByEmail('fbr@cityhospital.test'));
        $this->get($url('branding/logo.png'))->assertOk();
        $this->get($url("patients/{$other->id}/report.pdf"))->assertForbidden();

        $this->actingAs($this->userByEmail('patient@cityhospital.test'));
        $this->get($url("patients/{$portalPatient->id}/own.pdf"))->assertOk();
        $this->get($url("patients/{$other->id}/report.pdf"))->assertForbidden();

        $this->actingAs($this->userByEmail('doctor@cityhospital.test'));
        $this->get($url("patients/{$other->id}/report.pdf"))->assertOk();
    }

    public function test_cnic_is_validated_and_stored_in_one_format(): void
    {
        $this->actingAsTenantUser('reception@cityhospital.test');

        Volt::test('tenant.patients.form')->set('form.first_name', 'Bilal')->set('form.cnic', '12345')->call('save')->assertHasErrors('form.cnic');
        Volt::test('tenant.patients.form')->set('form.first_name', 'Bilal')->set('form.cnic', '3520298765431')->call('save')->assertHasNoErrors();

        $patient = Patient::where('first_name', 'Bilal')->latest('id')->firstOrFail();
        $this->assertSame('35202-9876543-1', $patient->cnic);
        $this->assertTrue(Patient::search('35202-9876543-1')->whereKey($patient->id)->exists());

        Volt::test('tenant.patients.form')->set('form.cnic', '35202-9876543-1')->assertSee('This CNIC is already registered to');
    }
}
