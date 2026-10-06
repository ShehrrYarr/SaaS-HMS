<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Department;
use App\Models\ExpenseCategory;
use App\Models\Hospital;
use App\Models\Plan;
use App\Models\Staff;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Creates a fully usable hospital (tenant): default roles + permissions,
 * the Hospital Admin account, optional starter accounts for the other roles,
 * starter departments and the subscription.
 */
class HospitalProvisioner
{
    /**
     * Roles the Super Admin can create a starter account for, with the HR staff record each one gets:
     * [staff type, designation, department]. FBR Officer is an outside (tax authority) login, so no staff record.
     */
    public const STARTER_ROLES = [
        'Doctor' => ['doctor', 'Medical Officer', 'General Medicine'],
        'Nurse' => ['nurse', 'Staff Nurse', null],
        'Receptionist' => ['receptionist', 'Front Desk Officer', null],
        'Pharmacist' => ['pharmacist', 'Pharmacist', 'Pharmacy'],
        'Lab Technician' => ['lab_technician', 'Lab Technologist', 'Pathology'],
        'Radiologist' => ['radiologist', 'Radiologist', 'Radiology'],
        'Accountant' => ['accountant', 'Accountant', null],
        'HR Manager' => ['hr', 'HR Manager', null],
        'FBR Officer' => null,
    ];

    /** @param  array<int, array{role: string, name: string, email: string, password: string}>  $accounts  starter accounts (STARTER_ROLES) */
    public function create(array $hospitalData, array $admin, Plan $plan, string $cycle = 'monthly', bool $trial = true, array $accounts = []): Hospital
    {
        return DB::transaction(function () use ($hospitalData, $admin, $plan, $cycle, $trial, $accounts) {
            Permissions::sync();

            $code = strtoupper($hospitalData['code'] ?? Str::upper(Str::substr(preg_replace('/[^A-Za-z]/', '', $hospitalData['name']), 0, 3)));
            $hospital = Hospital::create(array_merge([
                'slug' => $this->uniqueSlug($hospitalData['slug'] ?? $hospitalData['name']),
                'code' => $this->uniqueCode($code),
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'status' => $trial ? 'trial' : 'active',
                'trial_ends_at' => $trial ? now()->addDays($plan->trial_days)->toDateString() : null,
                'subscription_ends_at' => $trial ? now()->addDays($plan->trial_days)->toDateString() : ($cycle === 'yearly' ? now()->addYear() : now()->addMonth())->toDateString(),
            ], collect($hospitalData)->except(['slug', 'code'])->all()));

            tenancy()->run($hospital, function (Hospital $hospital) use ($admin, $plan, $cycle, $trial, $accounts) {
                $this->createDefaultRoles($hospital);

                foreach (['General Medicine', 'Pediatrics', 'Gynecology', 'Orthopedics', 'Cardiology', 'Surgery', 'Emergency'] as $name) {
                    Department::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'type' => 'clinical']);
                }
                foreach (['Pathology', 'Radiology', 'Pharmacy'] as $name) {
                    Department::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'type' => 'diagnostic']);
                }

                $this->createAccount($admin, 'Hospital Admin', ['admin', 'Hospital Administrator', null]);
                foreach ($accounts as $account) {
                    abort_unless(array_key_exists($account['role'], self::STARTER_ROLES), 422, "No starter account for the {$account['role']} role.");
                    $this->createAccount($account, $account['role'], self::STARTER_ROLES[$account['role']]);
                }
                foreach (['Salaries', 'Utilities', 'Rent', 'Maintenance', 'Medical Supplies', 'Miscellaneous'] as $name) {
                    ExpenseCategory::create(['name' => $name]);
                }
                BankAccount::cash();

                Subscription::create([
                    'plan_id' => $plan->id,
                    'billing_cycle' => $cycle,
                    'amount' => $trial ? 0 : $plan->price($cycle),
                    'starts_at' => now()->toDateString(),
                    'ends_at' => $hospital->subscription_ends_at,
                    'status' => 'active',
                ]);
            });

            return $hospital;
        });
    }

    /**
     * A login with one role in the current hospital, plus its HR staff record when $staff ([type, designation, department]) is given.
     *
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $data
     */
    protected function createAccount(array $data, string $role, ?array $staff): User
    {
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
        ]);
        $user->hospital_id = tenancy()->id();
        $user->email_verified_at = now();
        $user->save();
        $user->assignRole($role);

        if ($staff) {
            [$type, $designation, $department] = $staff;
            Staff::create([
                'user_id' => $user->id,
                'employee_code' => Sequence::code('employee', 'EMP', 4),
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'staff_type' => $type,
                'designation' => $designation,
                'department_id' => $department ? Department::where('name', $department)->value('id') : null,
                'specialization' => $type === 'doctor' ? $department : null,
                'joining_date' => now()->toDateString(),
            ]);
        }

        return $user;
    }

    /** Create (or top up) the default roles for a hospital. */
    public function createDefaultRoles(Hospital $hospital): void
    {
        tenancy()->run($hospital, function (Hospital $hospital) {
            $available = $hospital->availablePermissions();

            foreach (config('hms.default_roles') as $name => $permissions) {
                $role = Role::findOrCreate($name, 'web');
                $grant = $permissions === ['*'] ? $available : array_values(array_intersect($permissions, $available));
                $role->syncPermissions($grant);
            }
        });
    }

    /** After a plan change, strip permissions of modules no longer included. */
    public function syncPlanPermissions(Hospital $hospital): void
    {
        $hospital->unsetRelation('plan');

        tenancy()->run($hospital, function (Hospital $hospital) {
            $available = $hospital->availablePermissions();

            Role::where('hospital_id', $hospital->id)->get()->each(function (Role $role) use ($available) {
                if ($role->name === 'Hospital Admin') {
                    $role->syncPermissions($available);

                    return;
                }
                $role->syncPermissions($role->permissions->pluck('name')->intersect($available)->values()->all());
            });
        });
    }

    protected function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'hospital';
        $slug = $base;
        $i = 2;
        while (Hospital::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    protected function uniqueCode(string $code): string
    {
        $code = substr($code ?: 'HSP', 0, 8);
        $candidate = $code;
        $i = 2;
        while (Hospital::withTrashed()->where('code', $candidate)->exists()) {
            $candidate = $code.$i++;
        }

        return $candidate;
    }
}
