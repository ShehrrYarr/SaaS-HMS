<?php

namespace App\Services;

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
 * the Hospital Admin account, starter departments and the subscription.
 */
class HospitalProvisioner
{
    public function create(array $hospitalData, array $admin, Plan $plan, string $cycle = 'monthly', bool $trial = true): Hospital
    {
        return DB::transaction(function () use ($hospitalData, $admin, $plan, $cycle, $trial) {
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

            tenancy()->run($hospital, function (Hospital $hospital) use ($admin, $plan, $cycle, $trial) {
                $this->createDefaultRoles($hospital);

                $user = new User([
                    'name' => $admin['name'],
                    'email' => $admin['email'],
                    'phone' => $admin['phone'] ?? null,
                    'password' => $admin['password'],
                ]);
                $user->hospital_id = $hospital->id;
                $user->email_verified_at = now();
                $user->save();
                $user->assignRole('Hospital Admin');

                Staff::create([
                    'user_id' => $user->id,
                    'employee_code' => Sequence::code('employee', 'EMP', 4),
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'staff_type' => 'admin',
                    'designation' => 'Hospital Administrator',
                    'joining_date' => now()->toDateString(),
                ]);

                foreach (['General Medicine', 'Pediatrics', 'Gynecology', 'Orthopedics', 'Cardiology', 'Surgery', 'Emergency'] as $name) {
                    Department::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'type' => 'clinical']);
                }
                foreach (['Pathology', 'Radiology', 'Pharmacy'] as $name) {
                    Department::create(['name' => $name, 'code' => strtoupper(substr($name, 0, 4)), 'type' => 'diagnostic']);
                }
                foreach (['Salaries', 'Utilities', 'Rent', 'Maintenance', 'Medical Supplies', 'Miscellaneous'] as $name) {
                    ExpenseCategory::create(['name' => $name]);
                }

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
