<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global ICD-10 reference table (shared by all hospitals). */
class IcdCode extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where(fn ($q) => $q->where('code', 'like', "{$term}%")->orWhere('description', 'like', "%{$term}%"));
    }
}
