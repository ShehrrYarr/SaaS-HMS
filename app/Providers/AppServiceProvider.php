<?php

namespace App\Providers;

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsurePatient;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\IdentifyHospital;
use App\Models\AuditLog;
use App\Models\IpdAdmission;
use App\Models\OpdVisit;
use App\Models\User;
use App\Support\Livewire\SubdirectoryHandleRequests;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Tenancy::class);

        // Sub-directory deployments (/hms): Livewire's update endpoint must include the base path.
        $this->app->instance(HandleRequests::class, new SubdirectoryHandleRequests);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            // Absolute script URL so it resolves under a sub-directory (/hms). Livewire serves
            // livewire.js when debugging and livewire.min.js in production.
            config(['livewire.asset_url' => url(config('app.debug') ? 'livewire/livewire.js' : 'livewire/livewire.min.js')]);
        }

        Paginator::useBootstrapFive();

        Relation::morphMap([
            'opd_visit' => OpdVisit::class,
            'ipd_admission' => IpdAdmission::class,
        ]);

        // Livewire update requests hit /livewire/update — re-apply tenant & access middleware.
        Livewire::addPersistentMiddleware([
            IdentifyHospital::class,
            EnsureStaff::class,
            EnsurePatient::class,
            EnsureSuperAdmin::class,
            EnsureModuleEnabled::class,
        ]);

        $this->registerGates();
        $this->registerAuthEvents();

        Blade::if('module', fn (string $module) => (bool) hospital()?->hasModule($module));
    }

    protected function registerGates(): void
    {
        Gate::before(function (User $user, string $ability) {
            if ($user->is_super_admin) {
                return true;
            }

            $hospital = hospital();
            if (! $hospital || $user->hospital_id !== $hospital->id) {
                return null;
            }

            // Permissions belonging to modules outside the plan are always denied.
            $module = Permissions::moduleOf($ability);
            if ($module && ! $hospital->hasModule($module)) {
                return false;
            }

            if ($module && $user->hasRole('Hospital Admin')) {
                return true;
            }

            return null;
        });
    }

    protected function registerAuthEvents(): void
    {
        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            AuditLog::record('login', null, [], [], $event->user->name.' signed in');
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                AuditLog::record('logout', null, [], [], $event->user->name.' signed out');
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            AuditLog::record('login_failed', null, [], ['email' => $event->credentials['email'] ?? null], 'Failed sign-in attempt');
        });
    }
}
