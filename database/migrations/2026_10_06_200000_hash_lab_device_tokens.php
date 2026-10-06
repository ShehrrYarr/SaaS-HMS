<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Lab device API tokens were stored in plain text; keep them working but store only their SHA-256 hash. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lab_devices')->orderBy('id')->each(function ($device) {
            if (! preg_match('/^[0-9a-f]{64}$/', (string) $device->api_token)) {
                DB::table('lab_devices')->where('id', $device->id)->update(['api_token' => hash('sha256', $device->api_token)]);
            }
        });
    }

    public function down(): void
    {
        // Hashes cannot be reversed; regenerate device tokens from Lab → Devices if needed.
    }
};
