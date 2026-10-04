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
}
