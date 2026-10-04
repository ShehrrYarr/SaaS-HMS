<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use App\Services\DemoService;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoTest extends TestCase
{
    public function test_landing_page_presents_the_product_and_demo_buttons(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Try Demo Hospital')
            ->assertSee('Built for every department')
            ->assertSee(route('demo.login', 'admin'), false)
            ->assertSee(route('demo.login', 'patient'), false)
            ->assertSee('Professional'); // plans are listed
    }

    public function test_one_click_demo_signs_in_as_the_requested_role(): void
    {
        $this->post('/demo/admin')->assertRedirect('/h/city-hospital/dashboard');
        $this->assertSame('admin@cityhospital.test', auth()->user()->email);

        $this->post('/demo/doctor')->assertRedirect('/h/city-hospital/dashboard');
        $this->assertSame('doctor@cityhospital.test', auth()->user()->email);

        $this->post('/demo/patient')->assertRedirect('/h/city-hospital/portal');
        $this->get('/h/city-hospital/portal')->assertOk()->assertSee('Live demo');

        $this->post('/demo/superadmin')->assertNotFound();
    }

    public function test_demo_is_unavailable_when_disabled(): void
    {
        config(['hms.demo.enabled' => false]);

        $this->post('/demo/admin')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('Try Demo Hospital');
    }

    public function test_demo_account_credentials_cannot_be_changed(): void
    {
        $admin = User::where('email', 'admin@cityhospital.test')->firstOrFail();
        $admin->update(['email' => 'evil@example.com', 'password' => 'changed123', 'status' => 'inactive', 'name' => 'Renamed']);

        $admin->refresh();
        $this->assertSame('admin@cityhospital.test', $admin->email);
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame('Renamed', $admin->name); // harmless fields still editable

        // Accounts of other hospitals are unaffected.
        $sunrise = User::where('email', 'admin@sunrise.test')->firstOrFail();
        $sunrise->update(['password' => 'newpass123']);
        $this->assertTrue(Hash::check('newpass123', $sunrise->fresh()->password));
    }

    public function test_shared_demo_settings_are_locked_but_clinical_work_is_not(): void
    {
        $this->actingAsTenantUser('admin@cityhospital.test');
        $doctorRole = Role::where('hospital_id', $this->hospital()->id)->where('name', 'Doctor')->first();
        $before = $doctorRole->permissions->count();

        Volt::test('tenant.settings.roles')->set('roleId', $doctorRole->id)->set('selected', [])->call('save');
        $this->assertSame($before, $doctorRole->fresh()->permissions->count());

        Volt::test('tenant.settings.profile')->set('form.name', 'Hacked Hospital')->call('save');
        $this->assertSame('City General Hospital', $this->hospital()->fresh()->name);

        // Normal hospital work is allowed.
        Volt::test('tenant.patients.index')->call('openQuick')
            ->set('quick.first_name', 'Demo')->set('quick.age', 30)->set('quick.gender', 'female')
            ->call('saveQuick')->assertHasNoErrors();
        $this->assertTrue(Patient::where('first_name', 'Demo')->exists());
    }

    public function test_reset_wipes_and_rebuilds_the_demo_hospital_only(): void
    {
        $old = $this->hospital();
        tenancy()->run($old, fn () => Patient::create(['uhid' => 'TMP-1', 'first_name' => 'Visitor', 'gender' => 'male']));
        $sunriseUsers = User::where('hospital_id', $this->hospital('sunrise-clinic')->id)->count();

        $new = app(DemoService::class)->reset();

        $this->assertNotSame($old->id, $new->id);
        $this->assertNull(Hospital::withTrashed()->find($old->id));
        $this->assertSame(0, User::where('hospital_id', $old->id)->count());
        tenancy()->set($new);
        $this->assertFalse(Patient::where('first_name', 'Visitor')->exists());
        $this->assertGreaterThan(10, Patient::count());
        $this->assertSame($sunriseUsers, User::where('hospital_id', $this->hospital('sunrise-clinic')->id)->count());

        tenancy()->forget();
        $this->post('/demo/admin')->assertRedirect('/h/city-hospital/dashboard');
    }
}
