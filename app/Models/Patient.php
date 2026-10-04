<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use BelongsToHospital, Auditable, SoftDeletes;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'insurance_valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function tpa(): BelongsTo
    {
        return $this->belongsTo(Tpa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PatientHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function opdVisits(): HasMany
    {
        return $this->hasMany(OpdVisit::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(IpdAdmission::class);
    }

    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    public function clinicalNotes(): HasMany
    {
        return $this->hasMany(ClinicalNote::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }

    public function radiologyOrders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function pharmacySales(): HasMany
    {
        return $this->hasMany(PharmacySale::class);
    }

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function bloodRequests(): HasMany
    {
        return $this->hasMany(BloodRequest::class);
    }

    public function currentAdmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IpdAdmission::class)->where('status', 'admitted')->latestOfMany();
    }

    public function latestVital(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Vital::class)->latestOfMany('recorded_at');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }

    public function getAgeGenderAttribute(): string
    {
        return trim(($this->age !== null ? $this->age.'Y' : '').' / '.ucfirst(substr($this->gender, 0, 1)), ' /');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('files.show', ['path' => $this->photo_path]) : null;
    }

    public function initials(): string
    {
        return strtoupper(substr($this->first_name, 0, 1).substr((string) $this->last_name, 0, 1));
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('uhid', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('national_id', 'like', "%{$term}%")
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) like ?", ["%{$term}%"]);
        });
    }

    public static function generateUhid(): string
    {
        $prefix = hospital()?->uhid_prefix ?: (hospital()?->code ?: 'UH');

        return \App\Support\Sequence::code('uhid', $prefix, 6);
    }
}
