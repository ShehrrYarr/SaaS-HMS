<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('appointment_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('staff');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('appointment_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('source', ['walk_in', 'phone', 'online', 'portal'])->default('walk_in');
            $table->enum('mode', ['in_person', 'video'])->default('in_person');
            $table->unsignedSmallInteger('token_no')->nullable();
            $table->enum('status', ['booked', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'])->default('booked');
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('fee', 12, 2)->default(0);
            $table->string('video_room')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hospital_id', 'doctor_id', 'appointment_date']);
        });

        Schema::create('opd_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('visit_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('staff');
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('visit_date');
            $table->enum('visit_type', ['new', 'follow_up', 'emergency'])->default('new');
            $table->unsignedSmallInteger('token_no')->nullable();
            $table->string('chief_complaint')->nullable();
            $table->enum('status', ['waiting', 'in_consultation', 'completed', 'cancelled'])->default('waiting');
            $table->decimal('fee', 12, 2)->default(0);
            $table->date('follow_up_date')->nullable();
            $table->timestamp('consultation_started_at')->nullable();
            $table->timestamp('consultation_ended_at')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hospital_id', 'doctor_id', 'visit_date']);
        });

        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('visitable');
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->decimal('height', 5, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->decimal('blood_sugar', 6, 2)->nullable();
            $table->unsignedTinyInteger('pain_score')->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('visitable');
            $table->enum('type', ['consultation', 'progress', 'nursing', 'procedure', 'discharge'])->default('consultation');
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('visitable');
            $table->string('icd_code', 12)->nullable();
            $table->string('description');
            $table->enum('type', ['provisional', 'final'])->default('provisional');
            $table->foreignId('diagnosed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('prescription_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('staff');
            $table->nullableMorphs('visitable');
            $table->text('notes')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_date')->nullable();
            $table->enum('status', ['issued', 'partially_dispensed', 'dispensed', 'cancelled'])->default('issued');
            $table->timestamp('dispensed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('medicine_name');
            $table->string('dosage', 50)->nullable();
            $table->string('frequency', 50)->nullable();
            $table->string('duration', 50)->nullable();
            $table->string('route', 30)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('instructions')->nullable();
            $table->unsignedInteger('dispensed_qty')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('diagnoses');
        Schema::dropIfExists('clinical_notes');
        Schema::dropIfExists('vitals');
        Schema::dropIfExists('opd_visits');
        Schema::dropIfExists('appointments');
    }
};
