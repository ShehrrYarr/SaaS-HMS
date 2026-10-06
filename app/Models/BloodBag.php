<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BloodBag extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'collected_at' => 'date',
            'expires_at' => 'date',
            'screening' => 'array',
        ];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(BloodDonor::class, 'blood_donor_id');
    }

    public function crossmatches(): HasMany
    {
        return $this->hasMany(BloodCrossmatch::class);
    }

    public const COMPONENTS = ['whole_blood' => 'Whole Blood', 'prbc' => 'Packed RBC', 'ffp' => 'Fresh Frozen Plasma', 'platelets' => 'Platelets', 'cryo' => 'Cryoprecipitate'];

    /** Shelf life in days per component. */
    /** Transfusion-transmissible infection tests; a unit is released only when every one is negative. */
    public const SCREENING_TESTS = ['hiv', 'hbv', 'hcv', 'syphilis', 'malaria'];

    public const SHELF_LIFE = ['whole_blood' => 35, 'prbc' => 42, 'ffp' => 365, 'platelets' => 5, 'cryo' => 365];
}
