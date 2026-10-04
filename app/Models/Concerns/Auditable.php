<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Writes created / updated / deleted events to audit_logs.
 */
trait Auditable
{
    protected static array $auditExclude = ['password', 'remember_token', 'updated_at', 'created_at', 'lab_cert_password'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created', [], $model->getAttributes()));

        static::updated(function (Model $model) {
            $changes = $model->getChanges();
            $old = Arr::only($model->getOriginal(), array_keys($changes));
            static::writeAudit($model, 'updated', $old, $changes);
        });

        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted', $model->getAttributes(), []));
    }

    protected static function writeAudit(Model $model, string $event, array $old, array $new): void
    {
        $old = Arr::except($old, static::$auditExclude);
        $new = Arr::except($new, static::$auditExclude);

        if ($event === 'updated' && empty($new)) {
            return;
        }

        AuditLog::record($event, $model, $old, $new);
    }
}
