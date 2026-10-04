<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ot_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('status', ['available', 'in_use', 'cleaning', 'maintenance'])->default('available');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('surgeries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('surgery_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipd_admission_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ot_room_id')->constrained();
            $table->string('procedure_name');
            $table->enum('surgery_type', ['minor', 'major', 'emergency'])->default('major');
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->enum('status', ['scheduled', 'pre_op', 'in_progress', 'post_op', 'completed', 'cancelled'])->default('scheduled');
            $table->foreignId('surgeon_id')->constrained('staff');
            $table->string('anesthesia_type', 30)->nullable();
            $table->json('pre_op_checklist')->nullable();
            $table->json('post_op_checklist')->nullable();
            $table->text('pre_op_notes')->nullable();
            $table->text('operative_notes')->nullable();
            $table->text('post_op_notes')->nullable();
            $table->decimal('charges', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('surgery_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('surgery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('role', 30);
            $table->timestamps();
        });

        Schema::create('blood_donors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('donor_no', 30);
            $table->string('name');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->date('date_of_birth')->nullable();
            $table->string('blood_group', 5);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->date('last_donation_date')->nullable();
            $table->boolean('is_eligible')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('blood_bags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('bag_no', 30);
            $table->string('blood_group', 5);
            $table->enum('component', ['whole_blood', 'prbc', 'ffp', 'platelets', 'cryo'])->default('whole_blood');
            $table->unsignedSmallInteger('volume_ml')->default(450);
            $table->foreignId('blood_donor_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('source', ['donation', 'external'])->default('donation');
            $table->date('collected_at');
            $table->date('expires_at');
            $table->enum('status', ['quarantine', 'available', 'reserved', 'issued', 'expired', 'discarded'])->default('quarantine');
            $table->json('screening')->nullable();
            $table->string('storage_location', 50)->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'blood_group', 'status']);
        });

        Schema::create('blood_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('request_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipd_admission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('blood_group', 5);
            $table->enum('component', ['whole_blood', 'prbc', 'ffp', 'platelets', 'cryo'])->default('whole_blood');
            $table->unsignedSmallInteger('units')->default(1);
            $table->enum('priority', ['routine', 'urgent'])->default('routine');
            $table->enum('status', ['pending', 'crossmatched', 'issued', 'cancelled'])->default('pending');
            $table->string('notes')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('blood_crossmatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blood_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blood_bag_id')->constrained();
            $table->enum('result', ['compatible', 'incompatible']);
            $table->string('notes')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('tested_at');
            $table->dateTime('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_crossmatches');
        Schema::dropIfExists('blood_requests');
        Schema::dropIfExists('blood_bags');
        Schema::dropIfExists('blood_donors');
        Schema::dropIfExists('surgery_team');
        Schema::dropIfExists('surgeries');
        Schema::dropIfExists('ot_rooms');
    }
};
