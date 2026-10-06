<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login account. Platform users have hospital_id = NULL (super admins);
 * every other account belongs to exactly one hospital (staff or patient).
 *
 * Users are intentionally NOT globally scoped (authentication must resolve
 * them before the tenant is known). Always query tenant users through
 * User::forCurrentHospital().
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'status', 'avatar_path', 'signature_path', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Public demo accounts are shared by every visitor: their credentials & status are immutable.
        static::updating(function (User $user) {
            if (! $user->isDemoAccount()) {
                return;
            }
            foreach (['email', 'password', 'status', 'hospital_id', 'is_super_admin'] as $field) {
                if ($user->isDirty($field)) {
                    $user->setRawAttributes(array_merge($user->getAttributes(), [$field => $user->getRawOriginal($field)]));
                }
            }
        });
    }

    public function isDemoAccount(): bool
    {
        if (! config('hms.demo.enabled') || ! $this->hospital_id) {
            return false;
        }
        $email = $this->exists ? $this->getRawOriginal('email') : $this->email;

        return collect(config('hms.demo.accounts'))->pluck('email')->contains($email)
            && Hospital::whereKey($this->getRawOriginal('hospital_id') ?? $this->hospital_id)->value('slug') === config('hms.demo.hospital');
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class);
    }

    public function scopeForCurrentHospital($query)
    {
        return $query->where('hospital_id', tenancy()->id() ?? 0);
    }

    public function isPatientOnly(): bool
    {
        return $this->hospital_id !== null
            && $this->roles->isNotEmpty()
            && $this->roles->every(fn ($role) => $role->name === 'Patient');
    }

    /** A tax-authority (FBR) login: it can only open the read-only FBR register. */
    public function isFbrOfficer(): bool
    {
        return $this->hospital_id !== null && $this->hasRole('FBR Officer');
    }

    public function isHospitalAdmin(): bool
    {
        return $this->hasRole('Hospital Admin');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? route('files.show', ['path' => $this->avatar_path])
            : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name));

        return strtoupper(substr($parts[0] ?? '', 0, 1).substr($parts[1] ?? '', 0, 1));
    }

    public function roleLabel(): string
    {
        if ($this->is_super_admin) {
            return 'Super Admin';
        }

        return $this->roles->pluck('name')->join(', ') ?: 'User';
    }

    /** Where this user lands after login. */
    public function homeUrl(): string
    {
        if ($this->is_super_admin) {
            return route('admin.dashboard');
        }

        $slug = $this->hospital?->slug;

        if ($this->isFbrOfficer()) {
            return route('fbr.dashboard', ['hospital' => $slug]);
        }

        return $this->isPatientOnly()
            ? route('portal.dashboard', ['hospital' => $slug])
            : route('tenant.dashboard', ['hospital' => $slug]);
    }
}
