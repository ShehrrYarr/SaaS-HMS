<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Demo / development data.
 *
 * Logins (password for every account: "password"):
 *   Super Admin  : superadmin@hms.test            -> /admin/login
 *   Hospital     : City General Hospital          -> /h/city-hospital/login
 *     admin@cityhospital.test        Hospital Admin
 *     doctor@cityhospital.test       Doctor (Dr. Sarah Ahmed, Cardiology)
 *     doctor2@cityhospital.test      Doctor (Dr. James Wilson, General Medicine)
 *     nurse@cityhospital.test        Nurse
 *     reception@cityhospital.test    Receptionist
 *     pharmacist@cityhospital.test   Pharmacist
 *     lab@cityhospital.test          Lab Technician
 *     radiology@cityhospital.test    Radiologist
 *     accounts@cityhospital.test     Accountant
 *     hr@cityhospital.test           HR Manager
 *   Patient portal: patient@cityhospital.test (or OTP with UHID) -> /h/city-hospital/portal/login
 *   Second tenant (isolation testing): admin@sunrise.test -> /h/sunrise-clinic/login
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Permissions::sync();

        $this->call(IcdCodeSeeder::class);

        $basic = Plan::updateOrCreate(['slug' => 'basic'], [
            'name' => 'Basic', 'description' => 'Clinics: OPD, appointments, pharmacy & billing.',
            'price_monthly' => 15000, 'price_yearly' => 150000, 'trial_days' => 14, 'sort_order' => 1,
            'modules' => ['appointments', 'opd', 'pharmacy', 'billing', 'portal'],
        ]);
        Plan::updateOrCreate(['slug' => 'professional'], [
            'name' => 'Professional', 'description' => 'Hospitals: adds IPD, laboratory, radiology, HR & telemedicine.',
            'price_monthly' => 35000, 'price_yearly' => 350000, 'trial_days' => 14, 'sort_order' => 2,
            'modules' => ['appointments', 'opd', 'ipd', 'pharmacy', 'laboratory', 'radiology', 'billing', 'hr', 'telemedicine', 'portal'],
        ]);
        $enterprise = Plan::updateOrCreate(['slug' => 'enterprise'], [
            'name' => 'Enterprise', 'description' => 'Everything, including OT & blood bank.',
            'price_monthly' => 75000, 'price_yearly' => 750000, 'trial_days' => 30, 'sort_order' => 3,
            'modules' => array_keys(Plan::sellableModules()),
        ]);

        $super = User::firstOrNew(['email' => config('hms.super_admin_email'), 'hospital_id' => null]);
        $super->forceFill([
            'name' => 'Platform Owner', 'password' => 'password', 'is_super_admin' => true,
            'status' => 'active', 'email_verified_at' => now(),
        ])->save();

        PlatformSetting::put([
            'platform_name' => config('app.name'),
            'support_email' => 'support@hms.test',
            'bank_details' => "Bank: Meezan Bank\nAccount title: HMS Cloud (Pvt) Ltd\nAccount no: 0101 0123456789\nIBAN: PK24MEZN0001010123456789",
            'invoice_tax_percent' => '0',
            'invoice_footer' => 'Thank you for choosing HMS Cloud.',
        ]);

        $this->call(DemoHospitalSeeder::class, false, ['plan' => $enterprise, 'basic' => $basic]);
    }
}
