<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabDevice extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function qcLogs(): HasMany
    {
        return $this->hasMany(LabQcLog::class);
    }
}
