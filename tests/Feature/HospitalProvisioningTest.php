<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Staff;
use App\Models\User;
use App\Services\HospitalProvisioner;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HospitalProvisioningTest extends TestCase
{
    protected function newHospitalForm()
    {
        $this->actingAs(User::where('is_super_admin', true)->firstOrFail());

        return Volt::test('admin.hospitals.create')
            ->set('hospital.name', 'Lakeside Hospital')->set('hospital.slug', 'lakeside')
            ->set('admin.name', 'Lakeside Admin')->set('admin.email', 'admin@lakeside.test')->set('admin.password', 'lakeside-pass-1');
    }

    public function test_super_admin_can_create_a_login_for_every_role(): void
    {
        $form = $this->newHospitalForm();
        foreach (array_keys(HospitalProvisioner::STARTER_ROLES) as $i => $role) {
            $form->set("accounts.{$i}.name", "{$role} One")->set("accounts.{$i}.email", 'user'.$i.'@lakeside.test');
        }
        $form->call('save')->assertHasNoErrors();

        $hospital = Hospital::where('slug', 'lakeside')->firstOrFail();
        tenancy()->set($hospital);
        app(PermissionRegistrar::class)->setPermissionsTeamId($hospital->id);

        foreach (array_keys(HospitalProvisioner::STARTER_ROLES) as $i => $role) {
            $user = User::where('hospital_id', $hospital->id)->where('email', 'user'.$i.'@lakeside.test')->firstOrFail();
            $this->assertTrue($user->hasRole($role), "{$role} login has its role");
            $this->assertSame($role !== 'FBR Officer', Staff::where('user_id', $user->id)->exists(), "{$role} staff record");
        }

        $doctor = Staff::where('staff_type', 'doctor')->firstOrFail();
        $this->assertSame('General Medicine', $doctor->department->name);
        $this->assertSame(10, User::where('hospital_id', $hospital->id)->count()); // admin + 9 roles
    }

    public function test_blank_rows_are_skipped_and_switching_off_creates_only_the_admin(): void
    {
        $this->newHospitalForm()->set('accounts.1.name', 'Nurse Only')->set('accounts.1.email', 'nurse@lakeside.test')
            ->call('save')->assertHasNoErrors();
        $hospital = Hospital::where('slug', 'lakeside')->firstOrFail();
        $this->assertSame(['admin@lakeside.test', 'nurse@lakeside.test'], User::where('hospital_id', $hospital->id)->orderBy('email')->pluck('email')->all());

        $this->newHospitalForm()->set('hospital.slug', 'lakeside-2')->set('starterAccounts', false)
            ->set('accounts.1.name', 'Ignored')->set('accounts.1.email', 'ignored@lakeside.test')->call('save')->assertHasNoErrors();
        $this->assertSame(1, User::where('hospital_id', Hospital::where('slug', 'lakeside-2')->value('id'))->count());
    }

    public function test_starter_rows_are_validated(): void
    {
        $this->newHospitalForm()
            ->set('accounts.0.name', 'Dr Half')                                                    // no email
            ->set('accounts.1.name', 'Same')->set('accounts.1.email', 'same@lakeside.test')
            ->set('accounts.2.name', 'Same')->set('accounts.2.email', 'SAME@lakeside.test')        // duplicate
            ->set('accounts.3.name', 'Admin twin')->set('accounts.3.email', 'Admin@Lakeside.test') // the admin's email
            ->set('accounts.4.name', 'Short')->set('accounts.4.email', 'short@lakeside.test')->set('accounts.4.password', 'abc')
            ->call('save')
            ->assertHasErrors(['accounts.0.email', 'accounts.1.email', 'accounts.3.email', 'accounts.4.password']);
        $this->assertNull(Hospital::where('slug', 'lakeside')->first());

        // A tampered request cannot add rows for other roles (e.g. a second Hospital Admin).
        $this->newHospitalForm()->set('accounts.9', ['name' => 'X', 'email' => 'x@lakeside.test', 'password' => 'x-pass-1234'])
            ->call('save')->assertHasErrors('accounts');
        $this->assertNull(Hospital::where('slug', 'lakeside')->first());
    }
}
