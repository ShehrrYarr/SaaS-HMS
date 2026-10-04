<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DemoHospitalSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/**
 * Keeps the public demo hospital fresh: wipe every row of the tenant and re-seed it.
 */
class DemoService
{
    /** Permanently delete a hospital and all of its tenant data & files. */
    public function wipe(Hospital $hospital): void
    {
        $id = $hospital->id;
        $userIds = User::where('hospital_id', $id)->pluck('id');
        $roleIds = DB::table('roles')->where('hospital_id', $id)->pluck('id');
        $tables = collect(Schema::getTables())->pluck('name')
            ->reject(fn ($t) => $t === 'hospitals')
            ->filter(fn ($t) => Schema::hasColumn($t, 'hospital_id'));

        Schema::disableForeignKeyConstraints();
        try {
            DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();
            DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $userIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            foreach ($tables as $table) {
                DB::table($table)->where('hospital_id', $id)->delete();
            }
            DB::table('hospitals')->where('id', $id)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Storage::disk('local')->deleteDirectory($hospital->storagePath());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Wipe the demo hospital (if present) and build it again with fresh demo data. */
    public function reset(): Hospital
    {
        $slug = config('hms.demo.hospital');
        if ($existing = Hospital::withTrashed()->where('slug', $slug)->first()) {
            $this->wipe($existing);
        }

        $plan = Plan::where('slug', 'enterprise')->first() ?? Plan::orderByDesc('price_monthly')->firstOrFail();
        $hospital = app(DemoHospitalSeeder::class)->createCityHospital($plan);
        tenancy()->forget();

        return $hospital;
    }
}
