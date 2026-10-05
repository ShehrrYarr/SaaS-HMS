<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RadiologyTest extends Model
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

    public function orders(): HasMany
    {
        return $this->hasMany(RadiologyOrder::class);
    }

    public const MODALITIES = ['xray' => 'X-Ray', 'ct' => 'CT Scan', 'mri' => 'MRI', 'ultrasound' => 'Ultrasound', 'mammography' => 'Mammography', 'fluoroscopy' => 'Fluoroscopy', 'other' => 'Other'];
}
