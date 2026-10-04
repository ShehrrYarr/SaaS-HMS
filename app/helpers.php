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
    function currency_symbol(?string $currency = null): string
    {
        $currency ??= hospital()?->currency ?? 'USD';

        return config("hms.currencies.{$currency}", $currency);
    }
}

if (! function_exists('money')) {
    function money(float|int|string|null $amount, ?string $currency = null): string
    {
        return currency_symbol($currency).' '.number_format((float) $amount, 2);
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

if (! function_exists('doctor_exists')) {
    /** Validation rule: an active doctor of the current hospital. */
    function doctor_exists(): \Illuminate\Validation\Rules\Exists
    {
        return tenant_exists('staff')->where('staff_type', 'doctor')->whereNull('deleted_at');
    }
}
