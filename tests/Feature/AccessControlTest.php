<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\SubscriptionService;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    public function test_modules_outside_the_plan_are_blocked_even_for_the_hospital_admin(): void
    {
        $this->actingAsTenantUser('admin@sunrise.test', 'sunrise-clinic'); // Basic plan: no laboratory

        $this->get($this->tenantUrl('lab/orders', 'sunrise-clinic'))->assertForbidden()->assertSee('not in your plan');
        $this->assertFalse(auth()->user()->can('lab.view'));
        $this->assertTrue(auth()->user()->can('pharmacy.sell'));
    }

    public function test_upgrading_the_plan_unlocks_modules(): void
    {
        $hospital = $this->hospital('sunrise-clinic');
        app(SubscriptionService::class)->changePlan($hospital, Plan::where('slug', 'enterprise')->first(), 'monthly');

        $this->actingAsTenantUser('admin@sunrise.test', 'sunrise-clinic');
        $this->get($this->tenantUrl('lab/orders', 'sunrise-clinic'))->assertOk();
    }

    public function test_roles_restrict_screens(): void
    {
        $this->actingAsTenantUser('pharmacist@cityhospital.test');
        $this->get($this->tenantUrl('pharmacy/pos'))->assertOk();
        $this->get($this->tenantUrl('patients/create'))->assertForbidden();
        $this->get($this->tenantUrl('billing/reports'))->assertForbidden();

        $this->actingAsTenantUser('doctor@cityhospital.test');
        $this->get($this->tenantUrl('doctor/workspace'))->assertOk();
        $this->get($this->tenantUrl('pharmacy/pos'))->assertForbidden();
        $this->get($this->tenantUrl('settings/roles'))->assertForbidden();
    }

    public function test_patient_accounts_are_kept_out_of_the_staff_area(): void
    {
        $this->actingAsTenantUser('patient@cityhospital.test');
        $this->get($this->tenantUrl('patients'))->assertRedirect('/h/city-hospital/portal');
    }

    public function test_guests_are_redirected_to_the_right_login(): void
    {
        $this->get($this->tenantUrl('dashboard'))->assertRedirect('/h/city-hospital/login');
        $this->get($this->tenantUrl('portal'))->assertRedirect('/h/city-hospital/portal/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_menu_hides_modules_and_screens_the_user_cannot_use(): void
    {
        $this->actingAsTenantUser('lab@cityhospital.test');
        $html = $this->get($this->tenantUrl('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Lab Orders', $html);
        $this->assertStringNotContainsString('Roles &amp; Permissions', $html);
        $this->assertStringNotContainsString('POS Billing', $html);
    }
}
