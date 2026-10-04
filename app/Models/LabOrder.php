<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LabOrder extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'doctor_id');
    }

    public function visitable(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabOrderItem::class);
    }

    public function orderedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Roll item statuses up into the order status. */
    public function refreshStatus(): void
    {
        $statuses = $this->items()->where('status', '!=', 'cancelled')->pluck('status');
        if ($statuses->isEmpty()) {
            $status = 'cancelled';
        } elseif ($statuses->every(fn ($s) => $s === 'approved')) {
            $status = 'approved';
        } elseif ($statuses->every(fn ($s) => in_array($s, ['completed', 'approved']))) {
            $status = 'completed';
        } elseif ($statuses->contains(fn ($s) => in_array($s, ['processing', 'completed', 'approved']))) {
            $status = 'processing';
        } elseif ($statuses->every(fn ($s) => $s !== 'pending')) {
            $status = 'sample_collected';
        } else {
            $status = 'ordered';
        }

        $attributes = ['status' => $status];
        if ($status === 'approved' && ! $this->approved_at) {
            $attributes += ['approved_at' => now(), 'approved_by' => auth()->id(), 'verification_code' => $this->verification_code ?: \Illuminate\Support\Str::random(32)];
        }
        $this->update($attributes);
    }

    public function total(): float
    {
        return (float) $this->items->where('status', '!=', 'cancelled')->sum('price');
    }
}
