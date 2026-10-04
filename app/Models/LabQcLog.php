<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabQcLog extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(LabTest::class, 'lab_test_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(LabDevice::class, 'lab_device_id');
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
