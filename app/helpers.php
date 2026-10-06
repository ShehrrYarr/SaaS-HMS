<?php

use App\Models\Hospital;
use App\Models\PlatformSetting;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

if (! function_exists('tenancy')) {
    function tenancy(): Tenancy
    {
        return app(Tenancy::class);
    }
}

if (! function_exists('hospital')) {
    /** The hospital (tenant) of the current request, if any. */
    function hospital(): ?Hospital
    {
        return tenancy()->hospital();
    }
}

if (! function_exists('currency_symbol')) {
    /** The whole platform works in Pakistani rupees. */
    function currency_symbol(): string
    {
        return config('hms.currency.symbol', 'Rs');
    }
}

if (! function_exists('rupees')) {
    /** Round an amount to whole rupees (nearest; .5 rounds up). */
    function rupees(float|int|string|null $amount): int
    {
        return (int) round((float) $amount, 0, PHP_ROUND_HALF_UP);
    }
}

if (! function_exists('csv_safe')) {
    /**
     * One CSV row with spreadsheet formulas neutralised: a name typed as "=HYPERLINK(...)" would
     * otherwise run when the export is opened in Excel. Numbers (including negatives) are left alone.
     */
    function csv_safe(array $row): array
    {
        return array_map(fn ($v) => is_string($v) && $v !== '' && ! is_numeric($v) && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v, $row);
    }
}

if (! function_exists('money')) {
    /** e.g. "Rs 1,250" — amounts are always whole rupees. */
    function money(float|int|string|null $amount): string
    {
        $value = rupees($amount);

        return ($value < 0 ? '-' : '').currency_symbol().' '.number_format(abs($value));
    }
}

if (! function_exists('fmt_date')) {
    function fmt_date($date, string $format = 'd M Y'): string
    {
        if (! $date) {
            return '—';
        }

        return ($date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date))->format($format);
    }
}

if (! function_exists('fmt_datetime')) {
    function fmt_datetime($date): string
    {
        return fmt_date($date, 'd M Y, h:i A');
    }
}

if (! function_exists('fmt_time')) {
    function fmt_time($time): string
    {
        return $time ? Carbon::parse($time)->format('h:i A') : '—';
    }
}

if (! function_exists('platform_setting')) {
    function platform_setting(string $key, mixed $default = null): mixed
    {
        return PlatformSetting::get($key, $default);
    }
}

if (! function_exists('status_color')) {
    /** Maps any workflow status to a Bootstrap contextual colour. */
    function status_color(?string $status): string
    {
        return match ($status) {
            'active', 'paid', 'completed', 'approved', 'available', 'dispensed', 'present', 'compatible',
            'received', 'settled', 'pass', 'discharged', 'issued_ok', 'normal' => 'success',
            'trial', 'booked', 'confirmed', 'ordered', 'scheduled', 'draft', 'pending', 'submitted', 'reserved',
            'quarantine', 'issued' => 'info',
            'checked_in', 'waiting', 'in_consultation', 'processing', 'sample_collected', 'collected', 'partial',
            'partially_received', 'partially_dispensed', 'under_review', 'performed', 'reported', 'pre_op',
            'in_progress', 'post_op', 'late', 'half_day', 'cleaning', 'warning', 'unpaid', 'pending_verification',
            'crossmatched', 'in_use', 'admitted', 'partially_approved', 'low', 'high' => 'warning',
            'suspended', 'cancelled', 'no_show', 'rejected', 'expired', 'discarded', 'absent', 'occupied', 'fail',
            'incompatible', 'maintenance', 'inactive', 'critical_low', 'critical_high', 'returned' => 'danger',
            default => 'secondary',
        };
    }
}

if (! function_exists('label')) {
    /** "partially_received" => "Partially Received" */
    function label(?string $value): string
    {
        return $value ? ucwords(str_replace(['_', '-'], ' ', $value)) : '—';
    }
}

if (! function_exists('human_bytes')) {
    function human_bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}

if (! function_exists('asset_v')) {
    /** asset() with a file-modification-time cache buster (?v=...). */
    function asset_v(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}

if (! function_exists('tenant_exists')) {
    /** Validation rule: the id must exist in $table AND belong to the current hospital. */
    function tenant_exists(string $table, string $column = 'id'): \Illuminate\Validation\Rules\Exists
    {
        return \Illuminate\Validation\Rule::exists($table, $column)->where('hospital_id', tenancy()->id() ?? 0);
    }
}

if (! function_exists('normalize_phone')) {
    /**
     * One format for Pakistani mobiles so look-ups (OTP sign-in, duplicates) match however the
     * number was typed: "0300 1234567", "3001234567", "0092 300…" and "+92 300…" -> "+923001234567".
     * Landlines and foreign numbers are kept as typed.
     */
    function normalize_phone(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0092')) {
            $digits = substr($digits, 2);
        }

        return match (true) {
            strlen($digits) === 11 && str_starts_with($digits, '03') => '+92'.substr($digits, 1),
            strlen($digits) === 10 && str_starts_with($digits, '3') => '+92'.$digits,
            strlen($digits) === 12 && str_starts_with($digits, '923') => '+'.$digits,
            default => $phone,
        };
    }
}

if (! function_exists('tenant_unique')) {
    /** Validation rule: value not used yet in this table for the current hospital (ignoring the record being edited). */
    function tenant_unique(string $table, string $column, ?int $ignoreId = null): \Illuminate\Validation\Rules\Unique
    {
        return \Illuminate\Validation\Rule::unique($table, $column)->where('hospital_id', tenancy()->id() ?? 0)->ignore($ignoreId);
    }
}

if (! function_exists('bank_account_exists')) {
    /** Validation rule: an active bank / cash account of the current hospital. */
    function bank_account_exists(): \Illuminate\Validation\Rules\Exists
    {
        return tenant_exists('bank_accounts')->where('is_active', true);
    }
}

if (! function_exists('doctor_exists')) {
    /** Validation rule: an active doctor of the current hospital. */
    function doctor_exists(): \Illuminate\Validation\Rules\Exists
    {
        return tenant_exists('staff')->where('staff_type', 'doctor')->whereNull('deleted_at');
    }
}

if (! function_exists('is_demo_hospital')) {
    /** True when the current (or given) hospital is the public demo hospital. */
    function is_demo_hospital(?\App\Models\Hospital $hospital = null): bool
    {
        $hospital ??= hospital();

        return (bool) config('hms.demo.enabled') && $hospital && $hospital->slug === config('hms.demo.hospital');
    }
}
