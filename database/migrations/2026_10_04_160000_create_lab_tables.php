<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('sample_type', 30)->default('blood');
            $table->string('container', 50)->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('turnaround_hours')->default(24);
            $table->string('method')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lab_test_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30)->nullable(); // device/LIS mapping code
            $table->string('name');
            $table->string('unit', 30)->nullable();
            $table->enum('result_type', ['numeric', 'text', 'option'])->default('numeric');
            $table->json('options')->nullable();
            $table->decimal('ref_min', 12, 3)->nullable();
            $table->decimal('ref_max', 12, 3)->nullable();
            $table->decimal('male_min', 12, 3)->nullable();
            $table->decimal('male_max', 12, 3)->nullable();
            $table->decimal('female_min', 12, 3)->nullable();
            $table->decimal('female_max', 12, 3)->nullable();
            $table->decimal('critical_low', 12, 3)->nullable();
            $table->decimal('critical_high', 12, 3)->nullable();
            $table->string('ref_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('order_no', 30);
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->nullableMorphs('visitable');
            $table->enum('priority', ['routine', 'urgent', 'stat'])->default('routine');
            $table->enum('status', ['ordered', 'sample_collected', 'processing', 'completed', 'approved', 'cancelled'])->default('ordered');
            $table->text('clinical_notes')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ordered_at');
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('verification_code', 40)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('lab_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_no')->nullable();
            $table->enum('protocol', ['hl7', 'astm', 'csv', 'api'])->default('api');
            $table->string('api_token', 80)->unique();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->constrained();
            $table->decimal('price', 12, 2)->default(0);
            $table->enum('status', ['pending', 'collected', 'processing', 'completed', 'approved', 'cancelled'])->default('pending');
            $table->string('sample_barcode', 40)->nullable()->index();
            $table->timestamp('sample_collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('results_entered_at')->nullable();
            $table->foreignId('results_entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lab_device_id')->nullable()->constrained()->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_parameter_id')->constrained()->cascadeOnDelete();
            $table->string('value')->nullable();
            $table->string('flag', 20)->nullable(); // normal, low, high, critical_low, critical_high, abnormal
            $table->timestamps();
            $table->unique(['lab_order_item_id', 'lab_test_parameter_id']);
        });

        Schema::create('lab_qc_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lab_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('parameter')->nullable();
            $table->string('control_lot', 50)->nullable();
            $table->enum('control_level', ['low', 'normal', 'high'])->default('normal');
            $table->string('expected_value', 50)->nullable();
            $table->string('observed_value', 50)->nullable();
            $table->enum('result', ['pass', 'warning', 'fail'])->default('pass');
            $table->text('corrective_action')->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('logged_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_qc_logs');
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_devices');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_test_parameters');
        Schema::dropIfExists('lab_tests');
        Schema::dropIfExists('lab_test_categories');
    }
};
