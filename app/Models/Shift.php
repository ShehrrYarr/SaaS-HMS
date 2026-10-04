<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    public function assignments(): HasMany
    {
        return $this->hasMany(StaffShift::class);
    }
}
