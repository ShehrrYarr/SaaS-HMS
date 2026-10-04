<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\BloodBag;
use App\Models\BloodDonor;
use App\Models\Department;
use App\Models\DoctorSchedule;
use App\Models\Hospital;
use App\Models\LabDevice;
use App\Models\LabTest;
use App\Models\LabTestCategory;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\OtRoom;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\RadiologyTest;
use App\Models\ServiceCharge;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Supplier;
use App\Models\Tpa;
use App\Models\User;
use App\Models\Ward;
use App\Services\HospitalProvisioner;
use App\Support\Sequence;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoHospitalSeeder extends Seeder
{
    public function run(Plan $plan, Plan $basic): void
    {
        if (Hospital::where('slug', 'city-hospital')->exists()) {
            $this->command?->warn('Demo hospital already exists – skipping.');

            return;
        }

        $this->createCityHospital($plan);

        app(HospitalProvisioner::class)->create([
            'name' => 'Sunrise Clinic', 'slug' => 'sunrise-clinic', 'code' => 'SRC',
            'email' => 'hello@sunrise.test', 'city' => 'Riverside', 'country' => 'United States', 'currency' => 'USD',
        ], ['name' => 'Sunrise Admin', 'email' => 'admin@sunrise.test', 'password' => 'password'], $basic, 'monthly', true);
    }

    /**
     * The public demo hospital (also used by `php artisan hms:reset-demo`).
     */
    public function createCityHospital(Plan $plan): Hospital
    {
        $provisioner = app(HospitalProvisioner::class);

        $hospital = $provisioner->create([
            'name' => 'City General Hospital', 'slug' => 'city-hospital', 'code' => 'CGH',
            'email' => 'info@cityhospital.test', 'phone' => '+1 555 0100', 'address' => '125 Main Street',
            'city' => 'Springfield', 'state' => 'IL', 'country' => 'United States', 'postal_code' => '62701',
            'registration_no' => 'REG-2026-0042', 'tax_no' => 'TX-998877',
            'currency' => 'USD', 'timezone' => 'UTC', 'tax_label' => 'Tax', 'tax_rate' => 5, 'uhid_prefix' => 'CGH',
            'settings' => ['queue_display_key' => Str::random(24), 'invoice_footer' => 'Get well soon!'],
        ], ['name' => 'Hospital Administrator', 'email' => 'admin@cityhospital.test', 'password' => 'password'], $plan, 'yearly', false);

        tenancy()->run($hospital, function () use ($hospital) {
            $this->seedHospital($hospital);
            (new DemoActivitySeeder)->seed($hospital);
        });

        return $hospital;
    }

    protected function seedHospital(Hospital $hospital): void
    {
        $dept = Department::pluck('id', 'name');

        // ---------------------------------------------------------- staff
        $doctors = [];
        foreach ([
            ['Sarah Ahmed', 'doctor@cityhospital.test', 'Cardiology', 'Cardiologist', 'MBBS, FCPS (Cardiology)', 60, 40, 20],
            ['James Wilson', 'doctor2@cityhospital.test', 'General Medicine', 'Consultant Physician', 'MBBS, MD', 40, 25, 15],
            ['Emily Chen', null, 'Pediatrics', 'Pediatrician', 'MBBS, DCH', 45, 30, 15],
            ['Michael Brown', null, 'Orthopedics', 'Orthopedic Surgeon', 'MBBS, MS (Ortho)', 70, 45, 25],
        ] as [$name, $email, $department, $designation, $qualification, $fee, $followUp, $commission]) {
            $doctors[] = $this->staff($name, $email, 'Doctor', [
                'staff_type' => 'doctor', 'department_id' => $dept[$department] ?? null, 'designation' => $designation,
                'qualification' => $qualification, 'specialization' => $department, 'license_no' => 'PMC-'.random_int(10000, 99999),
                'consultation_fee' => $fee, 'follow_up_fee' => $followUp, 'commission_percent' => $commission, 'basic_salary' => 6000,
            ]);
        }

        foreach ($doctors as $i => $doctor) {
            foreach ([1, 2, 3, 4, 5, 6] as $day) {
                DoctorSchedule::create(['staff_id' => $doctor->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '13:00', 'slot_minutes' => 15, 'room' => 'OPD-'.($i + 1)]);
            }
            if ($i < 2) {
                foreach ([1, 3, 5] as $day) {
                    DoctorSchedule::create(['staff_id' => $doctor->id, 'day_of_week' => $day, 'start_time' => '17:00', 'end_time' => '20:00', 'slot_minutes' => 20, 'room' => 'OPD-'.($i + 1)]);
                }
            }
        }

        $this->staff('Grace Miller', 'nurse@cityhospital.test', 'Nurse', ['staff_type' => 'nurse', 'designation' => 'Staff Nurse', 'basic_salary' => 2500]);
        $this->staff('Olivia Davis', 'reception@cityhospital.test', 'Receptionist', ['staff_type' => 'receptionist', 'designation' => 'Front Desk Officer', 'basic_salary' => 1800]);
        $this->staff('Daniel Lee', 'pharmacist@cityhospital.test', 'Pharmacist', ['staff_type' => 'pharmacist', 'department_id' => $dept['Pharmacy'] ?? null, 'designation' => 'Chief Pharmacist', 'basic_salary' => 3000]);
        $this->staff('Aisha Khan', 'lab@cityhospital.test', 'Lab Technician', ['staff_type' => 'lab_technician', 'department_id' => $dept['Pathology'] ?? null, 'designation' => 'Senior Lab Technologist', 'basic_salary' => 2600]);
        $this->staff('Robert Taylor', 'radiology@cityhospital.test', 'Radiologist', ['staff_type' => 'radiologist', 'department_id' => $dept['Radiology'] ?? null, 'designation' => 'Consultant Radiologist', 'basic_salary' => 5500]);
        $this->staff('Linda Martinez', 'accounts@cityhospital.test', 'Accountant', ['staff_type' => 'accountant', 'designation' => 'Accounts Manager', 'basic_salary' => 3200]);
        $this->staff('Kevin Moore', 'hr@cityhospital.test', 'HR Manager', ['staff_type' => 'hr', 'designation' => 'HR Manager', 'basic_salary' => 3100]);
        $this->staff('Peter Clark', null, null, ['staff_type' => 'support', 'designation' => 'Ward Assistant', 'basic_salary' => 1200]);

        // ---------------------------------------------------------- master data
        foreach ([['Morning', '08:00', '16:00', 'success'], ['Evening', '16:00', '00:00', 'warning'], ['Night', '00:00', '08:00', 'info']] as [$n, $s, $e, $c]) {
            Shift::create(['name' => $n, 'start_time' => $s, 'end_time' => $e, 'color' => $c]);
        }

        foreach ([['Global Health Insurance', 'GHI'], ['MediCare Plus TPA', 'MCP'], ['SafeLife Assurance', 'SLA']] as [$n, $c]) {
            Tpa::create(['name' => $n, 'code' => $c, 'contact_person' => 'Claims Desk', 'phone' => '+1 555 02'.random_int(10, 99), 'email' => strtolower($c).'@tpa.test']);
        }

        foreach ([
            ['REG', 'Registration Fee', 'registration', 5], ['ECG', 'ECG', 'procedure', 15], ['DRS', 'Dressing (small)', 'procedure', 10],
            ['INJ', 'Injection Administration', 'procedure', 5], ['NEB', 'Nebulization', 'procedure', 8], ['AMB', 'Ambulance (within city)', 'transport', 40],
            ['NRS', 'Nursing Care (per day)', 'nursing', 25], ['DVC', 'Doctor Visit (IPD)', 'consultation', 30], ['O2', 'Oxygen (per hour)', 'consumable', 6],
        ] as [$code, $name, $cat, $price]) {
            ServiceCharge::create(['code' => $code, 'name' => $name, 'category' => $cat, 'price' => $price]);
        }

        foreach ([
            ['General Ward', 'general', 'Ground', 50, 10, 'GW'], ['ICU', 'icu', '1st', 300, 4, 'ICU'], ['Private Rooms', 'private', '2nd', 150, 5, 'PR'],
            ['Semi-Private', 'semi_private', '2nd', 90, 4, 'SP'], ['Emergency', 'emergency', 'Ground', 80, 3, 'ER'], ['NICU', 'nicu', '1st', 250, 2, 'NICU'],
        ] as [$name, $type, $floor, $charge, $count, $prefix]) {
            $ward = Ward::create(['name' => $name, 'type' => $type, 'floor' => $floor, 'charge_per_day' => $charge]);
            for ($b = 1; $b <= $count; $b++) {
                Bed::create(['ward_id' => $ward->id, 'bed_no' => $prefix.'-'.str_pad($b, 2, '0', STR_PAD_LEFT)]);
            }
        }
        Bed::whereHas('ward', fn ($q) => $q->where('type', 'general'))->skip(8)->take(1)->get()->each->update(['status' => 'cleaning']);

        OtRoom::create(['name' => 'OT-1 (Major)']);
        OtRoom::create(['name' => 'OT-2 (Minor)']);

        // ---------------------------------------------------------- pharmacy
        $suppliers = collect([
            ['PharmaDistributors Inc.', 'John Carter'], ['MedSupply Co.', 'Nina Patel'], ['HealthLine Wholesale', 'Omar Farooq'],
        ])->map(fn ($s) => Supplier::create(['name' => $s[0], 'contact_person' => $s[1], 'phone' => '+1 555 03'.random_int(10, 99), 'email' => Str::slug($s[0]).'@supplier.test']));

        $categories = collect(['Analgesics', 'Antibiotics', 'Antihypertensives', 'Antidiabetics', 'Antacids & GI', 'Vitamins & Supplements', 'Respiratory', 'IV Fluids'])
            ->mapWithKeys(fn ($n) => [$n => MedicineCategory::create(['name' => $n])->id]);

        $medicines = [
            ['Panadol 500mg', 'Paracetamol', 'Analgesics', 'tablet', '500mg', 'strip', 0.80, 1.20, false],
            ['Brufen 400mg', 'Ibuprofen', 'Analgesics', 'tablet', '400mg', 'strip', 1.10, 1.80, false],
            ['Augmentin 625mg', 'Amoxicillin + Clavulanate', 'Antibiotics', 'tablet', '625mg', 'strip', 4.50, 6.90, true],
            ['Azithromycin 500mg', 'Azithromycin', 'Antibiotics', 'tablet', '500mg', 'strip', 3.20, 5.00, true],
            ['Ciprofloxacin 500mg', 'Ciprofloxacin', 'Antibiotics', 'tablet', '500mg', 'strip', 2.10, 3.50, true],
            ['Ceftriaxone 1g Inj', 'Ceftriaxone', 'Antibiotics', 'injection', '1g', 'vial', 2.80, 4.50, true],
            ['Amlodipine 5mg', 'Amlodipine', 'Antihypertensives', 'tablet', '5mg', 'strip', 1.00, 1.70, true],
            ['Losartan 50mg', 'Losartan Potassium', 'Antihypertensives', 'tablet', '50mg', 'strip', 1.40, 2.30, true],
            ['Metformin 500mg', 'Metformin', 'Antidiabetics', 'tablet', '500mg', 'strip', 0.90, 1.50, true],
            ['Glimepiride 2mg', 'Glimepiride', 'Antidiabetics', 'tablet', '2mg', 'strip', 1.30, 2.10, true],
            ['Insulin Glargine 100IU', 'Insulin Glargine', 'Antidiabetics', 'injection', '100IU/ml', 'pen', 18.00, 26.00, true],
            ['Omeprazole 20mg', 'Omeprazole', 'Antacids & GI', 'capsule', '20mg', 'strip', 1.20, 2.00, false],
            ['Gaviscon Syrup', 'Sodium Alginate', 'Antacids & GI', 'syrup', '150ml', 'bottle', 3.00, 4.80, false],
            ['ORS Sachet', 'Oral Rehydration Salts', 'Antacids & GI', 'sachet', '20.5g', 'piece', 0.20, 0.40, false],
            ['Vitamin D3 50000IU', 'Cholecalciferol', 'Vitamins & Supplements', 'capsule', '50000IU', 'strip', 2.50, 4.00, false],
            ['Ferrous Sulfate 200mg', 'Ferrous Sulfate', 'Vitamins & Supplements', 'tablet', '200mg', 'strip', 0.60, 1.00, false],
            ['Salbutamol Inhaler', 'Salbutamol', 'Respiratory', 'inhaler', '100mcg', 'piece', 3.50, 5.50, true],
            ['Montelukast 10mg', 'Montelukast', 'Respiratory', 'tablet', '10mg', 'strip', 1.80, 3.00, true],
            ['Normal Saline 0.9% 500ml', 'Sodium Chloride', 'IV Fluids', 'infusion', '500ml', 'bottle', 0.90, 1.60, true],
            ['Ringer Lactate 500ml', 'Compound Sodium Lactate', 'IV Fluids', 'infusion', '500ml', 'bottle', 1.00, 1.80, true],
        ];
        foreach ($medicines as $i => [$name, $generic, $cat, $form, $strength, $unit, $buy, $sell, $rx]) {
            $medicine = Medicine::create([
                'medicine_category_id' => $categories[$cat], 'name' => $name, 'generic_name' => $generic, 'manufacturer' => ['GSK', 'Pfizer', 'Abbott', 'Novartis', 'Getz'][$i % 5],
                'form' => $form, 'strength' => $strength, 'unit' => $unit, 'barcode' => '890'.str_pad((string) ($i + 1), 10, '0', STR_PAD_LEFT),
                'rack_location' => 'R'.(intdiv($i, 4) + 1).'-S'.($i % 4 + 1), 'reorder_level' => 20, 'purchase_price' => $buy, 'sale_price' => $sell,
                'tax_percent' => 0, 'requires_prescription' => $rx,
            ]);
            $batches = [[now()->addMonths(18), random_int(80, 300)], [now()->addDays(random_int(20, 80)), random_int(5, 40)]];
            if ($i % 7 === 3) {
                $batches = [[now()->addDays(45), 12]]; // low stock + near expiry demo
            }
            foreach ($batches as $b => [$expiry, $qty]) {
                MedicineBatch::create([
                    'medicine_id' => $medicine->id, 'supplier_id' => $suppliers[$i % 3]->id, 'batch_no' => 'B'.date('y').str_pad((string) ($i * 10 + $b), 4, '0', STR_PAD_LEFT),
                    'mfg_date' => now()->subMonths(6)->toDateString(), 'expiry_date' => $expiry->toDateString(),
                    'quantity_received' => $qty, 'quantity_available' => $qty, 'purchase_price' => $buy, 'sale_price' => $sell,
                ]);
            }
        }

        // ---------------------------------------------------------- laboratory
        $this->seedLab();

        LabDevice::create(['name' => 'Sysmex XN-550', 'manufacturer' => 'Sysmex', 'model' => 'XN-550', 'serial_no' => 'SX-55021', 'protocol' => 'astm', 'api_token' => Str::random(40)]);

        // ---------------------------------------------------------- radiology
        foreach ([
            ['XR-CH', 'Chest X-Ray PA View', 'xray', 'Chest', 25], ['XR-KN', 'X-Ray Knee AP/Lateral', 'xray', 'Knee', 30],
            ['CT-BR', 'CT Brain (Plain)', 'ct', 'Brain', 120], ['CT-AB', 'CT Abdomen & Pelvis (Contrast)', 'ct', 'Abdomen', 220],
            ['MR-BR', 'MRI Brain', 'mri', 'Brain', 350], ['MR-LS', 'MRI Lumbar Spine', 'mri', 'Spine', 320],
            ['US-AB', 'Ultrasound Whole Abdomen', 'ultrasound', 'Abdomen', 45], ['US-PL', 'Ultrasound Pelvis', 'ultrasound', 'Pelvis', 40],
            ['US-OB', 'Obstetric Ultrasound', 'ultrasound', 'Pelvis', 50], ['MG-BL', 'Mammography Bilateral', 'mammography', 'Breast', 90],
        ] as [$code, $name, $mod, $part, $price]) {
            RadiologyTest::create(['code' => $code, 'name' => $name, 'modality' => $mod, 'body_part' => $part, 'price' => $price,
                'preparation' => in_array($mod, ['ultrasound']) ? 'Full bladder; fasting 6 hours for abdominal scans.' : null]);
        }

        // ---------------------------------------------------------- blood bank
        $groups = config('hms.blood_groups');
        foreach (range(1, 8) as $i) {
            $group = $groups[$i % 8];
            $donor = BloodDonor::create([
                'donor_no' => Sequence::code('donor', 'DNR', 4), 'name' => fake()->name(), 'gender' => $i % 3 ? 'male' : 'female',
                'date_of_birth' => now()->subYears(random_int(20, 50))->toDateString(), 'blood_group' => $group, 'phone' => fake()->numerify('+1 555 1#####'),
                'weight' => random_int(55, 90), 'last_donation_date' => now()->subDays(random_int(5, 60))->toDateString(),
            ]);
            BloodBag::create([
                'bag_no' => Sequence::code('blood-bag', 'BAG', 5), 'blood_group' => $group, 'component' => ['whole_blood', 'prbc', 'ffp', 'platelets'][$i % 4],
                'volume_ml' => 450, 'blood_donor_id' => $donor->id, 'collected_at' => $donor->last_donation_date,
                'expires_at' => now()->addDays(random_int(3, 30))->toDateString(), 'status' => 'available',
                'screening' => ['hiv' => 'negative', 'hbv' => 'negative', 'hcv' => 'negative', 'syphilis' => 'negative', 'malaria' => 'negative'],
                'storage_location' => 'Fridge-'.($i % 2 + 1),
            ]);
        }

        // ---------------------------------------------------------- patients
        $tpa = Tpa::first();
        $receptionist = User::forCurrentHospital()->where('email', 'reception@cityhospital.test')->first();
        $patients = collect();
        foreach (range(1, 18) as $i) {
            $gender = $i % 2 ? 'male' : 'female';
            $patients->push(Patient::create([
                'uhid' => Patient::generateUhid(),
                'first_name' => fake()->firstName($gender), 'last_name' => fake()->lastName(), 'gender' => $gender,
                'date_of_birth' => now()->subYears(random_int(2, 80))->subDays(random_int(0, 360))->toDateString(),
                'blood_group' => $groups[array_rand($groups)], 'phone' => '+1555'.str_pad((string) (2000000 + $i), 7, '0', STR_PAD_LEFT),
                'email' => null, 'address' => fake()->streetAddress(), 'city' => 'Springfield', 'country' => 'United States',
                'emergency_contact_name' => fake()->name(), 'emergency_contact_phone' => fake()->numerify('+1 555 3######'), 'emergency_contact_relation' => 'Spouse',
                'tpa_id' => $i % 4 === 0 ? $tpa->id : null, 'insurance_policy_no' => $i % 4 === 0 ? 'POL-'.random_int(100000, 999999) : null,
                'registration_type' => $i % 3 ? 'full' : 'quick', 'registered_by' => $receptionist?->id,
                'created_at' => now()->subDays(random_int(0, 120)),
            ]));
        }

        // Portal-enabled demo patient
        $portalPatient = $patients->first();
        $portalUser = new User(['name' => $portalPatient->full_name, 'email' => 'patient@cityhospital.test', 'phone' => $portalPatient->phone, 'password' => 'password']);
        $portalUser->hospital_id = $hospital->id;
        $portalUser->save();
        $portalUser->assignRole('Patient');
        $portalPatient->update(['user_id' => $portalUser->id, 'email' => 'patient@cityhospital.test']);
        $portalPatient->allergies()->create(['allergen' => 'Penicillin', 'type' => 'drug', 'reaction' => 'Skin rash', 'severity' => 'moderate']);
        $portalPatient->histories()->create(['type' => 'past_illness', 'title' => 'Hypertension', 'details' => 'On amlodipine since 2022']);

        // Today's appointments
        foreach ($patients->slice(1, 8)->values() as $i => $patient) {
            $doctor = $doctors[$i % 2];
            $start = now()->setTime(9, 0)->addMinutes(15 * intdiv($i, 2));
            Appointment::create([
                'appointment_no' => Sequence::code('appointment', 'APT'), 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
                'department_id' => $doctor->department_id, 'appointment_date' => today()->toDateString(),
                'start_time' => $start->format('H:i'), 'end_time' => $start->copy()->addMinutes(15)->format('H:i'),
                'source' => $i % 3 ? 'walk_in' : 'online', 'mode' => $i === 5 ? 'video' : 'in_person',
                'status' => 'booked', 'reason' => ['Fever', 'Chest pain', 'Follow-up', 'Cough', 'Back pain', 'Headache', 'BP check', 'Diabetes review'][$i],
                'fee' => $doctor->consultation_fee, 'created_by' => $receptionist?->id,
            ]);
        }
        // Upcoming appointment for the portal patient
        Appointment::create([
            'appointment_no' => Sequence::code('appointment', 'APT'), 'patient_id' => $portalPatient->id, 'doctor_id' => $doctors[0]->id,
            'department_id' => $doctors[0]->department_id, 'appointment_date' => today()->addDays(2)->toDateString(), 'start_time' => '10:00', 'end_time' => '10:15',
            'source' => 'portal', 'mode' => 'video', 'status' => 'confirmed', 'reason' => 'BP follow-up', 'fee' => $doctors[0]->consultation_fee,
        ]);
    }

    protected function staff(string $name, ?string $email, ?string $role, array $data): Staff
    {
        $user = null;
        if ($email) {
            $user = new User(['name' => $name, 'email' => $email, 'password' => 'password', 'phone' => fake()->numerify('+1 555 4######')]);
            $user->hospital_id = tenancy()->id();
            $user->email_verified_at = now();
            $user->save();
            if ($role) {
                $user->assignRole($role);
            }
        }

        return Staff::create(array_merge([
            'user_id' => $user?->id,
            'employee_code' => Sequence::code('employee', 'EMP', 4),
            'name' => $name,
            'email' => $email,
            'phone' => $user?->phone ?? fake()->numerify('+1 555 4######'),
            'gender' => in_array(explode(' ', $name)[0], ['Sarah', 'Emily', 'Grace', 'Olivia', 'Aisha', 'Linda']) ? 'female' : 'male',
            'joining_date' => now()->subMonths(random_int(3, 48))->toDateString(),
            'allowances' => 300,
            'bank_name' => 'Example Bank',
            'bank_account' => (string) random_int(1000000000, 9999999999),
        ], $data));
    }

    protected function seedLab(): void
    {
        $cat = fn (string $n) => LabTestCategory::firstOrCreate(['name' => $n])->id;

        $tests = [
            ['CBC', 'Complete Blood Count', 'Hematology', 'blood', 'EDTA (Purple)', 15, 6, [
                ['HGB', 'Hemoglobin', 'g/dL', null, null, 13.0, 17.0, 12.0, 15.5, 7, 20],
                ['WBC', 'Total WBC Count', '10^3/µL', 4.0, 11.0, null, null, null, null, 2, 30],
                ['RBC', 'RBC Count', '10^6/µL', null, null, 4.5, 5.9, 4.1, 5.1, null, null],
                ['HCT', 'Hematocrit (PCV)', '%', null, null, 40, 52, 36, 46, null, null],
                ['PLT', 'Platelet Count', '10^3/µL', 150, 450, null, null, null, null, 50, 1000],
                ['NEU', 'Neutrophils', '%', 40, 75, null, null, null, null, null, null],
                ['LYM', 'Lymphocytes', '%', 20, 45, null, null, null, null, null, null],
            ]],
            ['LFT', 'Liver Function Test', 'Biochemistry', 'blood', 'Gel (Yellow)', 25, 12, [
                ['TBIL', 'Total Bilirubin', 'mg/dL', 0.2, 1.2, null, null, null, null, null, 15],
                ['ALT', 'ALT (SGPT)', 'U/L', 7, 56, null, null, null, null, null, null],
                ['AST', 'AST (SGOT)', 'U/L', 10, 40, null, null, null, null, null, null],
                ['ALP', 'Alkaline Phosphatase', 'U/L', 44, 147, null, null, null, null, null, null],
                ['ALB', 'Albumin', 'g/dL', 3.5, 5.0, null, null, null, null, null, null],
            ]],
            ['RFT', 'Renal Function Test', 'Biochemistry', 'blood', 'Gel (Yellow)', 20, 12, [
                ['UREA', 'Blood Urea', 'mg/dL', 15, 45, null, null, null, null, null, null],
                ['CREA', 'Serum Creatinine', 'mg/dL', null, null, 0.7, 1.3, 0.6, 1.1, null, 10],
                ['UA', 'Uric Acid', 'mg/dL', null, null, 3.4, 7.0, 2.4, 6.0, null, null],
                ['NA', 'Sodium', 'mmol/L', 135, 145, null, null, null, null, 120, 160],
                ['K', 'Potassium', 'mmol/L', 3.5, 5.1, null, null, null, null, 2.5, 6.5],
            ]],
            ['LIPID', 'Lipid Profile', 'Biochemistry', 'blood', 'Gel (Yellow)', 22, 12, [
                ['CHOL', 'Total Cholesterol', 'mg/dL', null, 200, null, null, null, null, null, null],
                ['TG', 'Triglycerides', 'mg/dL', null, 150, null, null, null, null, null, null],
                ['HDL', 'HDL Cholesterol', 'mg/dL', 40, null, null, null, null, null, null, null],
                ['LDL', 'LDL Cholesterol', 'mg/dL', null, 130, null, null, null, null, null, null],
            ]],
            ['FBS', 'Fasting Blood Sugar', 'Biochemistry', 'blood', 'Fluoride (Grey)', 5, 2, [
                ['GLU', 'Glucose (Fasting)', 'mg/dL', 70, 100, null, null, null, null, 40, 400],
            ]],
            ['HBA1C', 'HbA1c', 'Biochemistry', 'blood', 'EDTA (Purple)', 18, 24, [
                ['A1C', 'Glycated Hemoglobin', '%', 4.0, 5.6, null, null, null, null, null, null],
            ]],
            ['TSH', 'Thyroid Stimulating Hormone', 'Immunology', 'blood', 'Gel (Yellow)', 20, 24, [
                ['TSH', 'TSH', 'µIU/mL', 0.4, 4.0, null, null, null, null, null, null],
            ]],
        ];

        foreach ($tests as [$code, $name, $category, $sample, $container, $price, $tat, $params]) {
            $test = LabTest::create(['lab_test_category_id' => $cat($category), 'code' => $code, 'name' => $name, 'sample_type' => $sample, 'container' => $container, 'price' => $price, 'turnaround_hours' => $tat, 'method' => 'Automated analyzer']);
            foreach ($params as $i => [$pcode, $pname, $unit, $min, $max, $mmin, $mmax, $fmin, $fmax, $clow, $chigh]) {
                $test->parameters()->create([
                    'code' => $pcode, 'name' => $pname, 'unit' => $unit, 'ref_min' => $min, 'ref_max' => $max,
                    'male_min' => $mmin, 'male_max' => $mmax, 'female_min' => $fmin, 'female_max' => $fmax,
                    'critical_low' => $clow, 'critical_high' => $chigh, 'sort_order' => $i,
                ]);
            }
        }

        $urine = LabTest::create(['lab_test_category_id' => $cat('Clinical Pathology'), 'code' => 'URE', 'name' => 'Urine Routine Examination', 'sample_type' => 'urine', 'container' => 'Sterile cup', 'price' => 8, 'turnaround_hours' => 4]);
        foreach ([['COL', 'Colour', 'text', null, 'Pale yellow'], ['APP', 'Appearance', 'option', ['Clear', 'Slightly turbid', 'Turbid'], 'Clear'], ['PH', 'pH', 'numeric', null, null], ['PRO', 'Protein', 'option', ['Nil', 'Trace', '+', '++', '+++'], 'Nil'], ['GLUU', 'Glucose', 'option', ['Nil', 'Trace', '+', '++', '+++'], 'Nil'], ['PUS', 'Pus Cells', 'text', null, '0-5 /HPF']] as $i => [$c, $n, $type, $opts, $ref]) {
            $urine->parameters()->create(['code' => $c, 'name' => $n, 'result_type' => $type, 'options' => $opts, 'ref_text' => $ref, 'ref_min' => $c === 'PH' ? 4.5 : null, 'ref_max' => $c === 'PH' ? 8 : null, 'sort_order' => $i]);
        }

        $dengue = LabTest::create(['lab_test_category_id' => $cat('Serology'), 'code' => 'NS1', 'name' => 'Dengue NS1 Antigen', 'sample_type' => 'blood', 'container' => 'Gel (Yellow)', 'price' => 18, 'turnaround_hours' => 4]);
        $dengue->parameters()->create(['code' => 'NS1', 'name' => 'Dengue NS1 Antigen', 'result_type' => 'option', 'options' => ['Negative', 'Positive'], 'ref_text' => 'Negative']);
    }
}
