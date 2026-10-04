<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHospital;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use BelongsToHospital;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function record(string $event, ?Model $model = null, array $old = [], array $new = [], ?string $description = null): void
    {
        $request = app()->runningInConsole() ? null : request();

        $hospitalId = tenancy()->id()
            ?? ($model?->getAttribute('hospital_id'))
            ?? ($model instanceof Hospital ? $model->id : null);

        static::withoutHospitalScope()->insert([
            'hospital_id' => $hospitalId,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $model ? $model->getMorphClass() : null,
            'auditable_id' => $model?->getKey(),
            'description' => $description ?? ($model ? class_basename($model).' '.$event : $event),
            'old_values' => $old ? json_encode($old) : null,
            'new_values' => $new ? json_encode($new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 250) : null,
            'url' => $request ? substr($request->fullUrl(), 0, 250) : null,
            'created_at' => now(),
        ]);
    }
}
