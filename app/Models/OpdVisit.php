<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OpdVisit extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'visit_date' => 'datetime',
            'follow_up_date' => 'date',
            'consultation_started_at' => 'datetime',
            'consultation_ended_at' => 'datetime',
            'fee' => 'decimal:2',
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
