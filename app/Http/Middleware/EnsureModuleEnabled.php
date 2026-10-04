<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('module:pharmacy') — blocks modules not in the hospital's plan. */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $hospital = hospital();

        if (! $hospital || ! $hospital->hasModule($module)) {
            return response()->view('errors.module-disabled', [
                'module' => config("hms.modules.{$module}.label", $module),
            ], 403);
        }

        return $next($request);
    }
}
