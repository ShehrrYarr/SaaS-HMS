<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OtRoom extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }
}
