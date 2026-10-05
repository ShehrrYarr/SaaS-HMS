<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use BelongsToHospital, Auditable, SoftDeletes;

    protected $table = 'staff';

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'basic_salary' => 'integer',
            'allowances' => 'integer',
            'deductions' => 'integer',
            'consultation_fee' => 'integer',
            'follow_up_fee' => 'integer',
            'commission_percent' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(DoctorLeave::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(DoctorCommission::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(StaffShift::class);
    }

    public const TYPES = [
        'doctor' => 'Doctor', 'nurse' => 'Nurse', 'receptionist' => 'Receptionist', 'pharmacist' => 'Pharmacist',
        'lab_technician' => 'Lab Technician', 'radiologist' => 'Radiologist', 'accountant' => 'Accountant',
        'hr' => 'HR', 'admin' => 'Administration', 'support' => 'Support Staff', 'other' => 'Other',
    ];

    public function scopeDoctors($query)
    {
        return $query->where('staff_type', 'doctor');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->staff_type === 'doctor' && ! str_starts_with($this->name, 'Dr') ? 'Dr. '.$this->name : $this->name;
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? route('files.show', ['path' => $this->photo_path]) : null;
    }

    public function isAvailableOn(\Carbon\CarbonInterface $date): bool
    {
        return $this->schedules->where('is_active', true)->where('day_of_week', $date->dayOfWeek)->isNotEmpty()
            && ! $this->leaves->contains(fn ($l) => $l->date->isSameDay($date));
    }
}
