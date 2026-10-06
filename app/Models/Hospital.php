<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * The tenant. Platform-level model (not scoped).
 */
class Hospital extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['lab_cert_password'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'date',
            'subscription_ends_at' => 'date',
            'tax_rate' => 'decimal:2',
            'lab_cert_password' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->withoutGlobalScopes();
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class)->withoutGlobalScopes();
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** Module keys enabled for this hospital (core modules always included). */
    public function modules(): array
    {
        $core = collect(config('hms.modules'))->filter(fn ($m) => ! empty($m['core']))->keys()->all();

        return array_values(array_unique(array_merge($core, $this->plan?->modules ?? [])));
    }

    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules(), true);
    }

    /** Permission names this hospital may grant (limited by plan modules). */
    public function availablePermissions(): array
    {
        return collect(config('hms.modules'))
            ->only($this->modules())
            ->flatMap(fn ($m) => array_keys($m['permissions']))
            ->values()
            ->all();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    public function logoUrl(): string
    {
        return $this->logo_path
            ? route('files.show', ['path' => $this->logo_path])
            : asset('assets/images/hms-logo.png');
    }

    public function fullAddress(): string
    {
        return collect([$this->address, $this->city, $this->state, $this->postal_code, $this->country])->filter()->join(', ');
    }

    public function storagePath(string $folder = ''): string
    {
        return trim("hospitals/{$this->id}/{$folder}", '/');
    }
}
