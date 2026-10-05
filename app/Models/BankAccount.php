<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place the hospital keeps money: the built-in Cash account or one of its banks.
 */
class BankAccount extends Model
{
    use BelongsToHospital, Auditable;

    protected $guarded = ['id', 'hospital_id'];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer',
            'opening_date' => 'date',
            'show_to_patients' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Cash first, then banks by name. */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw("type = 'cash' desc")->orderBy('name');
    }

    /** Adds money_in / money_out sums so ->balance needs no extra queries. */
    public function scopeWithTotals(Builder $query): void
    {
        $query->withSum(['transactions as money_in' => fn ($q) => $q->where('direction', 'in')], 'amount')
            ->withSum(['transactions as money_out' => fn ($q) => $q->where('direction', 'out')], 'amount');
    }

    public function isCash(): bool
    {
        return $this->type === 'cash';
    }

    /** "Cash" or "HBL · ****1234". */
    public function getLabelAttribute(): string
    {
        $number = preg_replace('/\s+/', '', (string) ($this->account_number ?: $this->iban));

        return $this->name.($number !== '' && ! $this->isCash() ? ' · ****'.substr($number, -4) : '');
    }

    public function getBalanceAttribute(): int
    {
        if (! array_key_exists('money_in', $this->attributes)) {
            $this->attributes['money_in'] = $this->transactions()->where('direction', 'in')->sum('amount');
            $this->attributes['money_out'] = $this->transactions()->where('direction', 'out')->sum('amount');
        }

        return (int) round($this->opening_balance + (float) $this->attributes['money_in'] - (float) $this->attributes['money_out']);
    }

    /** The hospital's Cash account (created on first use). */
    public static function cash(): self
    {
        return static::firstOrCreate(['type' => 'cash'], ['name' => 'Cash', 'opening_date' => today()->toDateString()]);
    }

    /** Active accounts for payment pickers: [id => label]. */
    public static function options(): array
    {
        static::cash();

        return static::active()->ordered()->get()->mapWithKeys(fn (self $a) => [$a->id => $a->label])->all();
    }
}
