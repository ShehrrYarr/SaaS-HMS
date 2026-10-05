<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of an IPD advance deposit paid back to the patient after the final bill took its share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipd_admissions', function (Blueprint $table) {
            $table->decimal('deposit_refunded', 12, 2)->default(0)->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('ipd_admissions', fn (Blueprint $table) => $table->dropColumn('deposit_refunded'));
    }
};
