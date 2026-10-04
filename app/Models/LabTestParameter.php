<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabTestParameter extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'ref_min' => 'float',
            'ref_max' => 'float',
            'male_min' => 'float',
            'male_max' => 'float',
            'female_min' => 'float',
            'female_max' => 'float',
            'critical_low' => 'float',
            'critical_high' => 'float',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }

    /** @return array{0: ?float, 1: ?float} */
    public function rangeFor(?string $gender): array
    {
        if ($gender === 'male' && ($this->male_min !== null || $this->male_max !== null)) {
            return [$this->male_min, $this->male_max];
        }
        if ($gender === 'female' && ($this->female_min !== null || $this->female_max !== null)) {
            return [$this->female_min, $this->female_max];
        }

        return [$this->ref_min, $this->ref_max];
    }

    public function rangeText(?string $gender): string
    {
        if ($this->ref_text) {
            return $this->ref_text;
        }
        [$min, $max] = $this->rangeFor($gender);
        if ($min === null && $max === null) {
            return '—';
        }
        if ($min === null) {
            return '< '.rtrim(rtrim(number_format($max, 3), '0'), '.');
        }
        if ($max === null) {
            return '> '.rtrim(rtrim(number_format($min, 3), '0'), '.');
        }

        return rtrim(rtrim(number_format($min, 3), '0'), '.').' - '.rtrim(rtrim(number_format($max, 3), '0'), '.');
    }

    public function flagFor(?string $value, ?string $gender): ?string
    {
        if ($value === null || $value === '' || $this->result_type !== 'numeric' || ! is_numeric($value)) {
            return $value === null || $value === '' ? null : 'normal';
        }
        $v = (float) $value;
        if ($this->critical_low !== null && $v <= $this->critical_low) {
            return 'critical_low';
        }
        if ($this->critical_high !== null && $v >= $this->critical_high) {
            return 'critical_high';
        }
        [$min, $max] = $this->rangeFor($gender);
        if ($min !== null && $v < $min) {
            return 'low';
        }
        if ($max !== null && $v > $max) {
            return 'high';
        }

        return 'normal';
    }
}
