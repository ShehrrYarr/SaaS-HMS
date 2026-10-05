<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ward extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'charge_per_day' => 'integer',
        ];
    }

    public function beds(): HasMany
    {
        return $this->hasMany(Bed::class);
    }

    public const TYPES = ['general' => 'General Ward', 'icu' => 'ICU', 'nicu' => 'NICU', 'private' => 'Private Room', 'semi_private' => 'Semi-Private', 'emergency' => 'Emergency', 'isolation' => 'Isolation', 'ot' => 'Operating Theater / Recovery'];
}
