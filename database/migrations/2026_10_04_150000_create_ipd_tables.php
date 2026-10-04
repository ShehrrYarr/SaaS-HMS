<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30)->default('general'); // general, icu, nicu, private, semi_private, emergency, isolation, ot
            $table->string('floor', 30)->nullable();
            $table->decimal('charge_per_day', 12, 2)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ward_id')->constrained()->cascadeOnDelete();
            $table->string('bed_no', 20);
            $table->enum('status', ['available', 'occupied', 'reserved', 'cleaning', 'maintenance'])->default('available');
            $table->decimal('charge_per_day', 12, 2)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['ward_id', 'bed_no']);
        });

        Schema::create('ipd_admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('admission_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('staff');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('admitted_at');
            $table->enum('admission_type', ['emergency', 'planned', 'transfer', 'daycare'])->default('planned');
            $table->string('reason')->nullable();
            $table->string('provisional_diagnosis')->nullable();
            $table->foreignId('tpa_id')->nullable()->constrained()->nullOnDelete();
            $table->string('insurance_policy_no')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->enum('status', ['admitted', 'discharged', 'cancelled'])->default('admitted');
            $table->date('expected_discharge_date')->nullable();
            $table->dateTime('discharged_at')->nullable();
            $table->string('discharge_type', 20)->nullable(); // normal, lama, referred, expired, absconded
            $table->text('discharge_summary')->nullable();
            $table->text('discharge_condition')->nullable();
            $table->text('discharge_instructions')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bed_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipd_admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bed_id')->constrained();
            $table->dateTime('from_at');
            $table->dateTime('to_at')->nullable();
            $table->decimal('charge_per_day', 12, 2)->default(0);
            $table->string('reason', 30)->default('admission');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ipd_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipd_admission_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30); // bed, nursing, doctor_visit, procedure, consumable, medicine, lab, radiology, ot, bloodbank, other
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->dateTime('charged_at');
            $table->nullableMorphs('source');
            $table->foreignId('doctor_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->boolean('billed')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipd_charges');
        Schema::dropIfExists('bed_allocations');
        Schema::dropIfExists('ipd_admissions');
        Schema::dropIfExists('beds');
        Schema::dropIfExists('wards');
    }
};
