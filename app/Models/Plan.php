<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
        ];
    }

    public function hospitals(): HasMany
    {
        return $this->hasMany(Hospital::class);
    }

    public function price(string $cycle): float
    {
        return (float) ($cycle === 'yearly' ? $this->price_yearly : $this->price_monthly);
    }

    /** Module keys that a plan can toggle (non-core). */
    public static function sellableModules(): array
    {
        return collect(config('hms.modules'))
            ->reject(fn ($m) => ! empty($m['core']))
            ->map(fn ($m) => $m['label'])
            ->all();
    }
}
