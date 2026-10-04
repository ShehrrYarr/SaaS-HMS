<?php

namespace App\Models\Concerns;

use App\Models\Hospital;
use App\Models\Scopes\HospitalScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: queries are scoped to the current hospital
 * and new records are always stamped with the current hospital id.
 */
trait BelongsToHospital
{
    public static function bootBelongsToHospital(): void
    {
        static::addGlobalScope(new HospitalScope);

        static::creating(function ($model) {
            if (tenancy()->check()) {
                // Always force the active tenant; never trust an incoming hospital_id.
                $model->hospital_id = tenancy()->id();
            }
        });
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public static function withoutHospitalScope()
    {
        return static::withoutGlobalScope(HospitalScope::class);
    }
}
