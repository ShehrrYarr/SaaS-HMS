<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medicine extends Model
{
    use BelongsToHospital, Auditable, SoftDeletes;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'integer',
            'sale_price' => 'integer',
            'tax_percent' => 'decimal:2',
            'requires_prescription' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MedicineCategory::class, 'medicine_category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public const FORMS = ['tablet', 'capsule', 'syrup', 'suspension', 'injection', 'infusion', 'cream', 'ointment', 'drops', 'inhaler', 'powder', 'sachet', 'other'];

    /** Sellable batches: in stock & not expired, FEFO (first-expiry-first-out). */
    public function sellableBatches(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MedicineBatch::class)
            ->where('quantity_available', '>', 0)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->orderBy('expiry_date');
    }

    public function scopeWithStock($query)
    {
        return $query->withSum(['batches as stock' => fn ($q) => $q->where('quantity_available', '>', 0)->whereDate('expiry_date', '>=', now()->toDateString())], 'quantity_available');
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
            ->orWhere('generic_name', 'like', "%{$term}%")->orWhere('barcode', $term));
    }

    /** Name with strength, without repeating it when the name already contains it. */
    public function getLabelAttribute(): string
    {
        return $this->strength && ! str_contains(strtolower($this->name), strtolower($this->strength)) ? $this->name." ".$this->strength : $this->name;
    }
}
