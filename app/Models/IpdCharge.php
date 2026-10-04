<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IpdCharge extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'charged_at' => 'datetime',
            'billed' => 'boolean',
            'amount' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'quantity' => 'float',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(IpdAdmission::class, 'ipd_admission_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'doctor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
