<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineBatch extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'expiry_date' => 'date',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast() && ! $this->expiry_date->isToday();
    }

    public function scopeExpiringWithin($query, int $days)
    {
        return $query->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '<=', now()->addDays($days)->toDateString());
    }
}
