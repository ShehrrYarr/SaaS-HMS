<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class IpdAdmission extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'expected_discharge_date' => 'date',
            'follow_up_date' => 'date',
            'deposit_amount' => 'decimal:2',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'doctor_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function tpa(): BelongsTo
    {
        return $this->belongsTo(Tpa::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BedAllocation::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(IpdCharge::class);
    }

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function pharmacySales(): HasMany
    {
        return $this->hasMany(PharmacySale::class);
    }

    public function vitals(): MorphMany
    {
        return $this->morphMany(Vital::class, 'visitable');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(ClinicalNote::class, 'visitable');
    }

    public function diagnoses(): MorphMany
    {
        return $this->morphMany(Diagnosis::class, 'visitable');
    }

    public function prescriptions(): MorphMany
    {
        return $this->morphMany(Prescription::class, 'visitable');
    }

    public function labOrders(): MorphMany
    {
        return $this->morphMany(LabOrder::class, 'visitable');
    }

    public function radiologyOrders(): MorphMany
    {
        return $this->morphMany(RadiologyOrder::class, 'visitable');
    }

    public function lengthOfStay(): int
    {
        return max(1, (int) ceil($this->admitted_at->diffInHours($this->discharged_at ?? now()) / 24));
    }

    /** Bed charges accrued per allocation (days are counted per started 24h, min 1). */
    public function accruedBedCharges(): float
    {
        return (float) $this->allocations->sum(function (BedAllocation $a) {
            $days = max(1, (int) ceil($a->from_at->diffInHours($a->to_at ?? now()) / 24));

            return $days * (float) $a->charge_per_day;
        });
    }

    public function totalCharges(): float
    {
        return $this->accruedBedCharges() + (float) $this->charges->sum('amount');
    }
}
