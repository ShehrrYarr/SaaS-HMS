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

    /** Tokens are stored as SHA-256 hashes: the plain token is shown once and a database copy is useless. */
    protected function apiToken(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn ($value) => static::hashToken($value));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function qcLogs(): HasMany
    {
        return $this->hasMany(LabQcLog::class);
    }
}
