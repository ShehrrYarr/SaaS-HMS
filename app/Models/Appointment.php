<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'appointment_date' => 'date',
            'checked_in_at' => 'datetime',
            'fee' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function opdVisit(): HasOne
    {
        return $this->hasOne(OpdVisit::class);
    }

    public function isVideo(): bool
    {
        return $this->mode === 'video';
    }

    public static function nextToken(int $doctorId, string $date): int
    {
        $max = max(
            (int) static::where('doctor_id', $doctorId)->whereDate('appointment_date', $date)->max('token_no'),
            (int) OpdVisit::where('doctor_id', $doctorId)->whereDate('visit_date', $date)->max('token_no'),
        );

        return $max + 1;
    }
}
