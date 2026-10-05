<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use App\Services\AppointmentService;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PortalTest extends TestCase
{
    public function test_a_phone_shared_by_two_portal_patients_asks_for_the_uhid(): void
    {
        tenancy()->set($this->hospital());
        $portal = Patient::whereNotNull('user_id')->firstOrFail();
        $sibling = Patient::whereNull('user_id')->whereKeyNot($portal->id)->firstOrFail();
        $user = new User(['name' => $sibling->full_name, 'email' => 'sibling@example.test', 'password' => 'secret-password', 'status' => 'active']);
        $user->hospital_id = $this->hospital()->id;
        $user->save();
        $sibling->update(['user_id' => $user->id, 'phone' => $portal->phone]);

        Volt::test('auth.portal-login')->set('identifier', $portal->phone)->call('sendOtp')
            ->assertHasErrors('identifier')->assertSet('otpUserId', null);

        Volt::test('auth.portal-login')->set('identifier', $sibling->uhid)->call('sendOtp')
            ->assertHasNoErrors()->assertSet('otpUserId', $user->id);
    }

    public function test_no_slots_are_offered_on_past_days(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');
        $doctor = Staff::doctors()->firstOrFail();

        $this->assertSame([], app(AppointmentService::class)->availableSlots($doctor, today()->subDays(7)->toDateString()));
        $this->assertNotEmpty(app(AppointmentService::class)->availableSlots($doctor, today()->next('Monday')->toDateString()));
    }
}
