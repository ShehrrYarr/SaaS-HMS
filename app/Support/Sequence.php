<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Gap-free, race-safe document numbering per hospital (UHID, invoice no, ...).
 */
class Sequence
{
    public static function next(string $key, ?int $hospitalId = null): int
    {
        $hospitalId ??= tenancy()->id() ?? 0;

        return DB::transaction(function () use ($key, $hospitalId) {
            DB::table('sequences')->insertOrIgnore([
                'hospital_id' => $hospitalId,
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('sequences')
                ->where('hospital_id', $hospitalId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            DB::table('sequences')->where('id', $row->id)->update([
                'next_value' => $row->next_value + 1,
                'updated_at' => now(),
            ]);

            return (int) $row->next_value;
        });
    }

    /** e.g. Sequence::code('invoice', 'INV') => INV-26-00042 (resets yearly). */
    public static function code(string $key, string $prefix, int $pad = 5, ?int $hospitalId = null): string
    {
        $year = now()->format('y');
        $number = static::next("{$key}-{$year}", $hospitalId);

        return sprintf('%s-%s-%s', strtoupper($prefix), $year, str_pad((string) $number, $pad, '0', STR_PAD_LEFT));
    }
}
