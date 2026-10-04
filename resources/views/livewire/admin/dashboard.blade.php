<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Platform Dashboard')] class extends Component
{
    public function with(SubscriptionService $billing): array
    {
        $paid = SubscriptionInvoice::where('status', 'paid');

        $months = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $revenueByMonth = $months->map(fn ($m) => (float) SubscriptionInvoice::where('status', 'paid')
            ->whereBetween('paid_at', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])->sum('total'));
        $signupsByMonth = $months->map(fn ($m) => Hospital::whereBetween('created_at', [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()])->count());

        $byPlan = Plan::withCount('hospitals')->orderBy('sort_order')->get();

        return [
            'stats' => [
                'hospitals' => Hospital::count(),
                'active' => Hospital::where('status', 'active')->count(),
                'trial' => Hospital::where('status', 'trial')->count(),
                'suspended' => Hospital::where('status', 'suspended')->count(),
                'revenue' => (float) $paid->sum('total'),
                'revenue_month' => (float) SubscriptionInvoice::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('total'),
                'mrr' => $billing->mrr(),
                'pending' => SubscriptionInvoice::whereIn('status', ['unpaid', 'pending_verification'])->sum('total'),
                'to_verify' => SubscriptionInvoice::where('status', 'pending_verification')->count(),
                'storage' => (int) Hospital::sum('storage_used_bytes'),
                'users' => User::whereNotNull('hospital_id')->count(),
                'patients' => Patient::count(),
            ],
            'revenueChart' => [
                'chart' => ['type' => 'bar', 'height' => 300],
                'series' => [['name' => 'Revenue', 'data' => $revenueByMonth->values()], ['name' => 'New hospitals', 'type' => 'line', 'data' => $signupsByMonth->values()]],
                'xaxis' => ['categories' => $months->map->format('M y')->values()],
                'yaxis' => [['title' => ['text' => 'Revenue']], ['opposite' => true, 'title' => ['text' => 'Signups']]],
                'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
                'dataLabels' => ['enabled' => false],
                'stroke' => ['width' => [0, 3]],
            ],
            'planChart' => [
                'chart' => ['type' => 'donut', 'height' => 300],
                'series' => $byPlan->pluck('hospitals_count')->values(),
                'labels' => $byPlan->pluck('name')->values(),
                'legend' => ['position' => 'bottom'],
            ],
            'recent' => Hospital::with('plan')->latest()->limit(6)->get(),
            'topStorage' => Hospital::orderByDesc('storage_used_bytes')->limit(5)->get(),
            'toVerify' => SubscriptionInvoice::with('hospital')->where('status', 'pending_verification')->latest()->limit(5)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Platform Dashboard" subtitle="Overview">
        <a href="{{ route('admin.hospitals.create') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i>New Hospital</a>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Total Revenue" :value="money($stats['revenue'], 'USD')" icon="ri-money-dollar-circle-line" color="success" :hint="'This month: '.money($stats['revenue_month'], 'USD')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="MRR (estimated)" :value="money($stats['mrr'], 'USD')" icon="ri-line-chart-line" color="primary" :hint="'Outstanding: '.money($stats['pending'], 'USD')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Active Hospitals" :value="$stats['active'].' / '.$stats['hospitals']" icon="ri-hospital-line" color="info" :hint="$stats['trial'].' on trial &middot; '.$stats['suspended'].' suspended'" :href="route('admin.hospitals.index')" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Storage Used" :value="human_bytes($stats['storage'])" icon="ri-hard-drive-2-line" color="warning" :hint="number_format($stats['users']).' users &middot; '.number_format($stats['patients']).' patients'" /></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Revenue &amp; signups (12 months)</h5></div>
                <div class="card-body"><div x-data="apexChart(@js($revenueChart))" wire:ignore></div></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Hospitals by plan</h5></div>
                <div class="card-body"><div x-data="apexChart(@js($planChart))" wire:ignore></div></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Recently onboarded</h5>
                    <a href="{{ route('admin.hospitals.index') }}" wire:navigate class="btn btn-sm btn-light-primary">View all</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hms table-hover mb-0">
                        <thead><tr><th>Hospital</th><th>Plan</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody>
                            @foreach ($recent as $h)
                                <tr>
                                    <td><a href="{{ route('admin.hospitals.show', $h) }}" wire:navigate class="fw-semibold">{{ $h->name }}</a><div class="text-muted fs-12">/h/{{ $h->slug }}</div></td>
                                    <td>{{ $h->plan?->name ?? '—' }}</td>
                                    <td><x-status :value="$h->status" /></td>
                                    <td>{{ fmt_date($h->created_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Payments awaiting verification <span class="badge bg-warning-subtle text-warning ms-1">{{ $stats['to_verify'] }}</span></h5>
                    <a href="{{ route('admin.invoices') }}" wire:navigate class="btn btn-sm btn-light-primary">Invoices</a>
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($toVerify as $inv)
                        <li class="list-group-item d-flex justify-content-between"><span>{{ $inv->hospital?->name }} &middot; {{ $inv->number }}</span><strong>{{ money($inv->total, $inv->currency) }}</strong></li>
                    @empty
                        <li class="list-group-item text-muted text-center py-4">Nothing to verify.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Top storage usage</h5></div>
                <ul class="list-group list-group-flush">
                    @foreach ($topStorage as $h)
                        <li class="list-group-item d-flex justify-content-between"><span>{{ $h->name }}</span><span class="text-muted">{{ human_bytes($h->storage_used_bytes) }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
