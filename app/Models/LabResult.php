<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabResult extends Model
{
    use BelongsToHospital;

    protected $guarded = ['id', 'hospital_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(LabOrderItem::class, 'lab_order_item_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(LabTestParameter::class, 'lab_test_parameter_id');
    }
}
