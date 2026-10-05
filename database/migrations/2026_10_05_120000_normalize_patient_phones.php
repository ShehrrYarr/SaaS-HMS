<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Patient phones are now stored in one format (+92…) so phone sign-in and duplicate
 * checks match however the number was typed; bring existing rows in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('patients')->whereNotNull('phone')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $normal = normalize_phone($row->phone);
                if ($normal !== $row->phone) {
                    DB::table('patients')->where('id', $row->id)->update(['phone' => $normal]);
                }
            }
        });
    }

    public function down(): void
    {
        // Normalised numbers stay valid; nothing to undo.
    }
};
