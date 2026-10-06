<?php

use App\Http\Middleware\EnsureFbrOfficer;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePatient;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\IdentifyHospital;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => IdentifyHospital::class,
            'staff' => EnsureStaff::class,
            'patient' => EnsurePatient::class,
            'fbr' => EnsureFbrOfficer::class,
            'super_admin' => EnsureSuperAdmin::class,
            'module' => EnsureModuleEnabled::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);

        // The tenant must be identified before auth so redirects & permission checks are tenant-aware.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: IdentifyHospital::class,
        );

        $middleware->prepend(\App\Http\Middleware\ResetTenancy::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin', 'admin/*')) {
                return route('admin.login');
            }
            if ($request->is('h/*') && tenancy()->check()) {
                return match (true) {
                    $request->is('h/*/portal', 'h/*/portal/*') => route('portal.login'),
                    $request->is('h/*/fbr', 'h/*/fbr/*') => route('fbr.login'),
                    default => route('tenant.login'),
                };
            }

            return route('home');
        });

        $middleware->redirectUsersTo(fn (Request $request) => $request->user()?->homeUrl() ?? route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
