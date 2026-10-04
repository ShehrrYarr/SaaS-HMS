<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    protected function sunrisePatient(): Patient
    {
        return tenancy()->run($this->hospital('sunrise-clinic'), fn () => Patient::create([
            'uhid' => Patient::generateUhid(), 'first_name' => 'Isolated', 'last_name' => 'Person', 'gender' => 'female',
        ]));
    }

    public function test_queries_are_scoped_to_the_active_hospital(): void
    {
        $other = $this->sunrisePatient();

        tenancy()->set($this->hospital());
        $this->assertNull(Patient::find($other->id));
        $this->assertSame(0, Patient::where('first_name', 'Isolated')->count());

        tenancy()->set($this->hospital('sunrise-clinic'));
        $this->assertNotNull(Patient::find($other->id));
    }

    public function test_new_records_are_always_stamped_with_the_active_hospital(): void
    {
        tenancy()->set($this->hospital());
        $p = Patient::create(['uhid' => 'X-1', 'first_name' => 'Stamp', 'gender' => 'male', 'hospital_id' => $this->hospital('sunrise-clinic')->id]);

        $this->assertSame($this->hospital()->id, $p->fresh()->hospital_id);
    }

    public function test_uhid_numbering_is_per_hospital(): void
    {
        tenancy()->set($this->hospital());
        $a = Patient::generateUhid();
        tenancy()->set($this->hospital('sunrise-clinic'));
        $b = Patient::generateUhid();

        $this->assertStringStartsWith('CGH-', $a);
        $this->assertStringStartsWith('SRC-', $b);
    }

    public function test_guessing_another_hospitals_record_id_returns_404(): void
    {
        $other = $this->sunrisePatient();
        $this->actingAsTenantUser('admin@cityhospital.test');

        $this->get($this->tenantUrl("patients/{$other->id}"))->assertNotFound();
        $this->get($this->tenantUrl("patients/{$other->id}/card"))->assertNotFound();
    }

    public function test_user_of_one_hospital_is_signed_out_when_opening_another_hospital(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');

        $this->get($this->tenantUrl('dashboard', 'sunrise-clinic'))->assertRedirect('/h/sunrise-clinic/login');
        $this->assertGuest();
    }

    public function test_login_is_bound_to_the_hospital_in_the_url(): void
    {
        // Correct password, wrong hospital => rejected.
        tenancy()->set($this->hospital());
        \Livewire\Volt\Volt::test('auth.tenant-login')
            ->set('email', 'admin@sunrise.test')->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');
    }

    public function test_hospital_users_cannot_open_the_super_admin_console(): void
    {
        $this->actingAs($this->userByEmail('admin@cityhospital.test'));
        $this->get('/admin')->assertForbidden();
    }

    public function test_suspended_hospital_blocks_staff_but_allows_subscription_page(): void
    {
        $this->hospital()->update(['status' => 'suspended', 'suspended_reason' => 'Unpaid']);
        $this->actingAsTenantUser('admin@cityhospital.test');

        $this->get($this->tenantUrl('dashboard'))->assertForbidden()->assertSee('suspended');
        $this->get($this->tenantUrl('subscription'))->assertOk();
    }

    public function test_patient_portal_only_exposes_the_patients_own_records(): void
    {
        $this->actingAsTenantUser('patient@cityhospital.test');
        $self = User::where('email', 'patient@cityhospital.test')->first()->patient;
        $foreign = Invoice::where('patient_id', '!=', $self->id)->first();

        $this->get($this->tenantUrl("portal/bills/{$foreign->id}/pdf"))->assertNotFound();
        $this->get($this->tenantUrl('dashboard'))->assertRedirect('/h/city-hospital/portal');
    }
}
