<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;

class Permissions
{
    protected static ?array $map = null;

    /** permission name => module key */
    public static function map(): array
    {
        return static::$map ??= collect(config('hms.modules'))
            ->flatMap(fn ($module, $key) => collect($module['permissions'])->keys()->mapWithKeys(fn ($p) => [$p => $key]))
            ->all();
    }

    public static function moduleOf(string $permission): ?string
    {
        return static::map()[$permission] ?? null;
    }

    public static function all(): array
    {
        return array_keys(static::map());
    }

    /** Make sure every configured permission exists (idempotent). */
    public static function sync(): void
    {
        foreach (static::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
