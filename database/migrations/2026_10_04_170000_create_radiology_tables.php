<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('radiology_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->enum('modality', ['xray', 'ct', 'mri', 'ultrasound', 'mammography', 'fluoroscopy', 'other'])->default('xray');
            $table->string('body_part')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->text('preparation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('radiology_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('order_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('radiology_test_id')->constrained();
            $table->nullableMorphs('visitable');
            $table->enum('priority', ['routine', 'urgent', 'stat'])->default('routine');
            $table->enum('status', ['ordered', 'scheduled', 'performed', 'reported', 'approved', 'cancelled'])->default('ordered');
            $table->text('clinical_history')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('performed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('technician_notes')->nullable();
            $table->text('findings')->nullable();
            $table->text('impression')->nullable();
            $table->foreignId('radiologist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reported_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('study_instance_uid')->nullable(); // DICOM / PACS reference
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('radiology_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('radiology_order_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime', 100)->nullable();
            $table->enum('type', ['dicom', 'image', 'pdf', 'other'])->default('image');
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radiology_attachments');
        Schema::dropIfExists('radiology_orders');
        Schema::dropIfExists('radiology_tests');
    }
};
