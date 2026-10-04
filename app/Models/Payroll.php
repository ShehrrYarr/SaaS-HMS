<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'basic' => 'decimal:2',
            'allowances' => 'decimal:2',
            'commission' => 'decimal:2',
            'deductions' => 'decimal:2',
            'absence_deduction' => 'decimal:2',
            'net_pay' => 'decimal:2',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(DoctorCommission::class);
    }
}
