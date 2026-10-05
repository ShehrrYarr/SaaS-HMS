<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Vital extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'temperature' => 'float',
            'weight' => 'float',
            'height' => 'float',
            'bmi' => 'float',
            'blood_sugar' => 'float',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected static function booted(): void
    {
        static::saving(function (Vital $v) {
            if ($v->weight && $v->height) {
                $v->bmi = round($v->weight / pow($v->height / 100, 2), 2);
            }
        });
    }

    public function getBpAttribute(): ?string
    {
        return $this->bp_systolic ? $this->bp_systolic.'/'.$this->bp_diastolic : null;
    }

    /** Outside the usual adult range, high or low (highlighted in the EMR). */
    public function abnormal(string $field): bool
    {
        return match ($field) {
            'bp' => (bool) $this->bp_systolic && ($this->bp_systolic >= 140 || $this->bp_systolic < 90
                || $this->bp_diastolic >= 90 || ($this->bp_diastolic && $this->bp_diastolic < 60)),
            'pulse' => (bool) $this->pulse && ($this->pulse > 100 || $this->pulse < 50),
            'temperature' => (bool) $this->temperature && ($this->temperature >= 38 || $this->temperature < 35),
            'respiratory_rate' => (bool) $this->respiratory_rate && ($this->respiratory_rate > 20 || $this->respiratory_rate < 12),
            'spo2' => (bool) $this->spo2 && $this->spo2 < 94,
            'blood_sugar' => (bool) $this->blood_sugar && ($this->blood_sugar >= 200 || $this->blood_sugar < 70),
            default => false,
        };
    }
}
