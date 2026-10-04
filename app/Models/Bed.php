<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bed extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'charge_per_day' => 'decimal:2',
        ];
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BedAllocation::class);
    }

    public function currentAdmission(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IpdAdmission::class)->where('status', 'admitted');
    }

    public function dailyCharge(): float
    {
        return (float) ($this->charge_per_day ?? $this->ward?->charge_per_day ?? 0);
    }

    public function getLabelAttribute(): string
    {
        return ($this->ward?->name ? $this->ward->name.' / ' : '').$this->bed_no;
    }
}
