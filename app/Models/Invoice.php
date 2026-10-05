<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'cancelled_at' => 'datetime',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'paid_amount' => 'integer',
            'insurance_amount' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(IpdAdmission::class, 'ipd_admission_id');
    }

    public function opdVisit(): BelongsTo
    {
        return $this->belongsTo(OpdVisit::class);
    }

    public function tpa(): BelongsTo
    {
        return $this->belongsTo(Tpa::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    public function getBalanceAttribute(): int
    {
        return rupees($this->total - $this->paid_amount - $this->insurance_amount);
    }

    /** Recompute totals from items & payments and derive the status. */
    public function recalculate(): void
    {
        $items = $this->items()->get();
        $subtotal = $items->sum(fn ($i) => rupees((float) $i->quantity * $i->unit_price));
        $itemDiscount = $items->sum('discount');
        $tax = $items->sum('tax_amount');
        $paid = (float) $this->payments()->where('is_refund', false)->sum('amount') - (float) $this->payments()->where('is_refund', true)->sum('amount');

        $this->subtotal = $subtotal;
        $this->tax = $tax;
        $this->total = max(0, $subtotal - $itemDiscount - rupees($this->discount) + $tax);
        $this->paid_amount = rupees($paid);

        if ($this->status !== 'cancelled' && $this->status !== 'draft') {
            $balance = $this->total - $this->paid_amount - $this->insurance_amount;
            $this->status = $balance <= 0 ? 'paid' : ($this->paid_amount > 0 || $this->insurance_amount > 0 ? 'partial' : 'unpaid');
        }
        $this->save();
    }
}
