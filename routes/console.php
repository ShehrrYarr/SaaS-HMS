<?php

use App\Models\Appointment;
use App\Models\Hospital;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\User;
use App\Notifications\HmsNotification;
use App\Services\BloodBankService;
use App\Services\DemoService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| HMS scheduled jobs  (server cron:  * * * * * php /path/artisan schedule:run)
|--------------------------------------------------------------------------
*/

Artisan::command('hms:billing-run', function (SubscriptionService $billing) {
    $result = $billing->runDailyBilling();
    $this->info("Renewal invoices generated: {$result['invoices']} · hospitals suspended: {$result['suspended']}");
})->purpose('Generate SaaS renewal invoices and suspend overdue hospitals');

Artisan::command('hms:daily-maintenance', function () {
    $alertDays = (int) config('hms.pharmacy_expiry_alert_days');

    Hospital::whereIn('status', ['active', 'trial'])->each(function (Hospital $hospital) use ($alertDays) {
        tenancy()->run($hospital, function (Hospital $hospital) use ($alertDays) {
            $notes = [];

            // Appointments left open on past days become no-shows.
            $noShows = Appointment::whereDate('appointment_date', '<', today())->whereIn('status', ['booked', 'confirmed'])->update(['status' => 'no_show']);
            if ($noShows) {
                $notes[] = "{$noShows} no-shows";
            }

            if ($hospital->hasModule('bloodbank')) {
                $expired = app(BloodBankService::class)->expireOld();
                $notes[] = "{$expired} blood units expired";
            }

            if ($hospital->hasModule('pharmacy')) {
                $expiring = MedicineBatch::with('medicine')->expiringWithin(30)->whereDate('expiry_date', '>=', today())->get();
                $low = Medicine::withStock()->where('is_active', true)->get()->filter(fn ($m) => (int) $m->stock <= $m->reorder_level);
                if ($expiring->isNotEmpty() || $low->isNotEmpty()) {
                    $users = User::where('hospital_id', $hospital->id)->permission('pharmacy.inventory')->get();
                    foreach ($users as $u) {
                        $u->notify(new HmsNotification(
                            'Pharmacy stock alerts',
                            $expiring->count().' batch(es) expire within 30 days · '.$low->count().' item(s) at or below re-order level.',
                            route('tenant.pharmacy.stock', ['hospital' => $hospital->slug, 'filter' => 'expiring']),
                            'ri-alarm-warning-line', 'warning'
                        ));
                    }
                }
                $notes[] = $expiring->count().' expiring batches, '.$low->count().' low-stock items';
            }

            // Storage usage of private uploads (patient documents, imaging, branding...).
            $bytes = collect(Storage::disk('local')->allFiles($hospital->storagePath()))->sum(fn ($f) => Storage::disk('local')->size($f));
            $hospital->forceFill(['storage_used_bytes' => $bytes])->saveQuietly();

            $this->line("{$hospital->name}: ".implode(' · ', $notes).' · storage '.human_bytes($bytes));
        });
    });
})->purpose('Per-hospital housekeeping: no-shows, blood expiry, pharmacy alerts, storage usage');

Artisan::command('hms:reset-demo {--force : Run even when the public demo is disabled}', function (DemoService $demo) {
    if (! config('hms.demo.enabled') && ! $this->option('force')) {
        $this->warn('Public demo is disabled (HMS_DEMO_ENABLED=false).');

        return;
    }
    $hospital = $demo->reset();
    $this->info("Demo hospital {$hospital->name} (/h/{$hospital->slug}) wiped and re-seeded.");
})->purpose('Wipe and re-seed the public demo hospital');

Schedule::command('hms:reset-demo')
    ->cron('0 */'.max(1, (int) config('hms.demo.reset_every_hours', 6)).' * * *')
    ->timezone('UTC') // the demo banner announces the next reset on these UTC hours
    ->when(fn () => (bool) config('hms.demo.enabled'))
    ->withoutOverlapping();
Schedule::command('hms:billing-run')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('hms:daily-maintenance')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->weekly();
