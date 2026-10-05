<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabTest extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LabTestCategory::class, 'lab_test_category_id');
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(LabTestParameter::class);
    }

    protected static function booted(): void
    {
        // keep parameters ordered
    }

    public function orderedParameters(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LabTestParameter::class)->orderBy('sort_order')->orderBy('id');
    }
}
