<?php

namespace Tests\Feature;

use App\Models\Patient;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    public function test_phone_numbers_are_stored_in_one_format_and_found_however_typed(): void
    {
        $this->actingAsTenantUser('reception@cityhospital.test');

        $patient = Patient::create(['uhid' => Patient::generateUhid(), 'first_name' => 'Hamza', 'gender' => 'male', 'phone' => '0300 123-4567']);

        $this->assertSame('+923001234567', $patient->phone);
        $this->assertTrue(Patient::search('0300 1234567')->whereKey($patient->id)->exists());
        $this->assertTrue(Patient::search('+92 300 1234567')->whereKey($patient->id)->exists());
        $this->assertSame('021 111 222 333', normalize_phone('021 111 222 333')); // landlines kept as typed
    }

    public function test_quick_registration_warns_before_creating_a_possible_duplicate(): void
    {
        $this->actingAsTenantUser('reception@cityhospital.test');
        $existing = Patient::where('phone', '+923002000001')->firstOrFail();
        $count = Patient::count();

        $component = Volt::test('tenant.patients.index')->call('openQuick')
            ->set('quick.first_name', 'Someone')->set('quick.gender', 'male')->set('quick.age', 40)->set('quick.phone', '0300 2000001')
            ->call('saveQuick');

        $this->assertSame($count, Patient::count());
        $this->assertSame([$existing->id], array_column($component->get('duplicates'), 'id'));

        $component->call('saveQuick', true);
        $this->assertSame($count + 1, Patient::count());
    }

    public function test_portal_otp_finds_the_patient_by_a_locally_typed_number(): void
    {
        tenancy()->set($this->hospital());
        $patient = Patient::where('email', 'patient@cityhospital.test')->firstOrFail();

        Volt::test('auth.portal-login')->set('identifier', '0'.substr($patient->phone, 3))->call('sendOtp')
            ->assertHasNoErrors()->assertSet('otpUserId', $patient->user_id);
    }
}
