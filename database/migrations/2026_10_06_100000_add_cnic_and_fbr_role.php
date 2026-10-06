<?php

use App\Models\Hospital;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Patients get a dedicated CNIC field (the FBR portal reports it), and every
 * hospital gets the read-only "FBR Officer" role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('cnic', 15)->nullable()->after('email');
            $table->index(['hospital_id', 'cnic']);
        });

        // "National ID / Passport" values that are really CNICs move to the new field.
        DB::table('patients')->whereNotNull('national_id')->orderBy('id')->each(function ($p) {
            $digits = preg_replace('/\D/', '', $p->national_id);
            if (strlen($digits) === 13 && preg_match('/^\d{5}-?\d{7}-?\d$/', trim($p->national_id))) {
                DB::table('patients')->where('id', $p->id)->update([
                    'cnic' => substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12),
                    'national_id' => null,
                ]);
            }
        });

        // Only create the new role; re-syncing the default roles would undo each hospital's permission edits.
        Hospital::query()->each(function (Hospital $hospital) {
            tenancy()->run($hospital, fn () => Role::findOrCreate('FBR Officer', 'web'));
        });
        tenancy()->forget();
    }

    public function down(): void
    {
        DB::table('patients')->whereNotNull('cnic')->whereNull('national_id')->update(['national_id' => DB::raw('cnic')]);

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['hospital_id', 'cnic']);
            $table->dropColumn('cnic');
        });

        Role::where('name', 'FBR Officer')->delete();
    }
};
