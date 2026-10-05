<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banks & Cash ledger per hospital, Rs as the only currency and whole-rupee amounts.
 */
return new class extends Migration
{
    /** Money columns (rounded to whole rupees). Percentages, quantities and clinical values are left alone. */
    protected array $money = [
        'appointments' => ['fee'],
        'bed_allocations' => ['charge_per_day'],
        'beds' => ['charge_per_day'],
        'doctor_commissions' => ['base_amount', 'amount'],
        'expenses' => ['amount', 'tax_amount'],
        'insurance_claims' => ['claim_amount', 'approved_amount', 'settled_amount'],
        'invoice_items' => ['unit_price', 'discount', 'tax_amount', 'total'],
        'invoices' => ['subtotal', 'discount', 'tax', 'total', 'paid_amount', 'insurance_amount'],
        'ipd_admissions' => ['deposit_amount'],
        'ipd_charges' => ['unit_price', 'amount'],
        'lab_order_items' => ['price'],
        'lab_tests' => ['price'],
        'medicine_batches' => ['purchase_price', 'sale_price'],
        'medicines' => ['purchase_price', 'sale_price'],
        'opd_visits' => ['fee'],
        'payments' => ['amount'],
        'payrolls' => ['basic', 'allowances', 'commission', 'deductions', 'absence_deduction', 'net_pay'],
        'pharmacy_sale_items' => ['unit_price', 'discount', 'total'],
        'pharmacy_sales' => ['subtotal', 'discount', 'tax', 'total', 'paid_amount'],
        'plans' => ['price_monthly', 'price_yearly'],
        'purchase_order_items' => ['unit_price', 'total'],
        'purchase_orders' => ['subtotal', 'tax', 'discount', 'total'],
        'radiology_orders' => ['price'],
        'radiology_tests' => ['price'],
        'service_charges' => ['price'],
        'staff' => ['basic_salary', 'allowances', 'deductions', 'consultation_fee', 'follow_up_fee'],
        'subscription_invoices' => ['amount', 'tax', 'total'],
        'subscriptions' => ['amount'],
        'surgeries' => ['charges'],
        'wards' => ['charge_per_day'],
    ];

    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('bank'); // cash (one per hospital) | bank
            $table->string('name', 120);
            $table->string('account_title', 120)->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('iban', 60)->nullable();
            $table->string('branch', 120)->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->boolean('show_to_patients')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['hospital_id', 'type']);
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 3); // in | out
            $table->decimal('amount', 14, 2);
            $table->string('type', 20); // receipt, refund, pharmacy, deposit, insurance, expense, salary, transfer, adjustment
            $table->nullableMorphs('source');
            $table->string('reference', 100)->nullable();
            $table->string('description')->nullable();
            $table->timestamp('transacted_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hospital_id', 'bank_account_id', 'transacted_at'], 'bank_tx_account_date_index');
        });

        foreach (['payments', 'pharmacy_sales', 'expenses', 'payrolls'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            });
        }
        Schema::table('ipd_admissions', function (Blueprint $table) {
            $table->foreignId('deposit_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
        });

        foreach (['plans', 'hospitals', 'subscription_invoices'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('currency', 3)->default('PKR')->change());
            DB::table($name)->update(['currency' => 'PKR']);
        }

        foreach ($this->money as $name => $columns) {
            DB::table($name)->update(collect($columns)->mapWithKeys(fn ($c) => [$c => DB::raw("ROUND(`{$c}`)")])->all());
        }

        $now = now();
        DB::table('hospitals')->pluck('id')->each(fn ($id) => DB::table('bank_accounts')->insert([
            'hospital_id' => $id, 'type' => 'cash', 'name' => 'Cash', 'opening_date' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now,
        ]));
    }

    public function down(): void
    {
        Schema::table('ipd_admissions', fn (Blueprint $table) => $table->dropConstrainedForeignId('deposit_account_id'));
        foreach (['payments', 'pharmacy_sales', 'expenses', 'payrolls'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('bank_account_id'));
        }
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
