<?php

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Invoice;
use App\Models\IpdAdmission;
use App\Models\LabOrderItem;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Payment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component
{
    public function with(): array
    {
        $user = auth()->user();
        $h = hospital();
        $today = today();
        $doctor = $user->staff?->staff_type === 'doctor' ? $user->staff : null;
        $days = collect(range(13, 0))->map(fn ($i) => $today->copy()->subDays($i));

        $cards = [];
        $charts = [];

        if ($user->can('patients.view')) {
            $cards[] = ['title' => 'Registered Patients', 'value' => number_format(Patient::count()), 'icon' => 'ri-user-heart-line', 'color' => 'primary',
                'hint' => Patient::whereDate('created_at', $today)->count().' new today', 'href' => route('tenant.patients.index')];
        }
        if ($h->hasModule('appointments') && $user->can('appointments.view')) {
            $q = Appointment::whereDate('appointment_date', $today)->when($doctor, fn ($q) => $q->where('doctor_id', $doctor->id));
            $cards[] = ['title' => $doctor ? 'My Appointments Today' : 'Appointments Today', 'value' => (clone $q)->whereNotIn('status', ['cancelled'])->count(), 'icon' => 'ri-calendar-check-line', 'color' => 'info',
                'hint' => (clone $q)->where('status', 'checked_in')->count().' checked in &middot; '.(clone $q)->where('status', 'completed')->count().' completed', 'href' => route('tenant.appointments.index')];
        }
        if ($h->hasModule('opd') && $user->can('opd.view')) {
            $q = OpdVisit::whereDate('visit_date', $today)->when($doctor, fn ($q) => $q->where('doctor_id', $doctor->id));
            $cards[] = ['title' => 'OPD Visits Today', 'value' => (clone $q)->count(), 'icon' => 'ri-stethoscope-line', 'color' => 'success',
                'hint' => (clone $q)->where('status', 'waiting')->count().' waiting', 'href' => route('tenant.opd.index')];
            $charts['opd'] = [
                'chart' => ['type' => 'area', 'height' => 280],
                'series' => [['name' => 'OPD visits', 'data' => $days->map(fn ($d) => OpdVisit::whereDate('visit_date', $d)->count())->values()]],
                'xaxis' => ['categories' => $days->map->format('d M')->values()],
                'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 2],
            ];
        }
        if ($h->hasModule('ipd') && $user->can('ipd.view')) {
            $total = Bed::count();
            $occupied = Bed::where('status', 'occupied')->count();
            $cards[] = ['title' => 'Bed Occupancy', 'value' => $occupied.' / '.$total, 'icon' => 'ri-hotel-bed-line', 'color' => 'warning',
                'hint' => ($total ? round($occupied / $total * 100) : 0).'% occupied &middot; '.IpdAdmission::whereDate('admitted_at', $today)->count().' admitted today', 'href' => route('tenant.ipd.beds')];
        }
        if ($h->hasModule('billing') && $user->can('billing.reports')) {
            $collected = Payment::whereDate('paid_at', $today)->where('is_refund', false)->sum('amount') - Payment::whereDate('paid_at', $today)->where('is_refund', true)->sum('amount');
            $cards[] = ['title' => 'Collections Today', 'value' => money($collected), 'icon' => 'ri-money-rupee-circle-line', 'color' => 'success',
                'hint' => 'Outstanding: '.money(Invoice::whereIn('status', ['unpaid', 'partial'])->get()->sum('balance')), 'href' => route('tenant.billing.reports')];
            $charts['revenue'] = [
                'chart' => ['type' => 'bar', 'height' => 280],
                'series' => [['name' => 'Collections', 'data' => $days->map(fn ($d) => rupees(Payment::whereDate('paid_at', $d)->where('is_refund', false)->sum('amount')))->values()]],
                'xaxis' => ['categories' => $days->map->format('d M')->values()],
                'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '50%']], 'dataLabels' => ['enabled' => false],
            ];
        }
        if ($h->hasModule('pharmacy') && $user->can('pharmacy.view')) {
            $low = Medicine::withStock()->get()->filter(fn ($m) => (int) $m->stock <= $m->reorder_level)->count();
            $expiring = MedicineBatch::expiringWithin(config('hms.pharmacy_expiry_alert_days'))->count();
            $cards[] = ['title' => 'Pharmacy Alerts', 'value' => $low.' low stock', 'icon' => 'ri-capsule-line', 'color' => 'danger',
                'hint' => $expiring.' batches expiring within '.config('hms.pharmacy_expiry_alert_days').' days', 'href' => route('tenant.pharmacy.stock')];
        }
        if ($h->hasModule('laboratory') && $user->can('lab.view')) {
            $cards[] = ['title' => 'Lab Work Pending', 'value' => LabOrderItem::whereIn('status', ['pending', 'collected', 'processing'])->count(), 'icon' => 'ri-flask-line', 'color' => 'info',
                'hint' => LabOrderItem::where('status', 'completed')->count().' awaiting approval', 'href' => route('tenant.lab.orders')];
        }

        $appointments = ($h->hasModule('appointments') && $user->can('appointments.view'))
            ? Appointment::with(['patient', 'doctor'])->whereDate('appointment_date', $today)
                ->when($doctor, fn ($q) => $q->where('doctor_id', $doctor->id))
                ->orderBy('start_time')->limit(8)->get()
            : collect();

        return [
            'cards' => $cards,
            'charts' => $charts,
            'appointments' => $appointments,
            'recentPatients' => $user->can('patients.view') ? Patient::latest()->limit(6)->get() : collect(),
            'doctor' => $doctor,
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Welcome, '.auth()->user()->name" :subtitle="now()->format('l, d F Y')">
        @if ($doctor && hospital()->hasModule('opd'))
            <a href="{{ route('tenant.doctor.workspace') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-stethoscope-line me-1"></i>My Workspace</a>
        @endif
        @can('patients.create')
            <a href="{{ route('tenant.patients.create') }}" wire:navigate class="btn btn-light-primary btn-sm"><i class="ri-user-add-line me-1"></i>Register Patient</a>
        @endcan
        @if (hospital()->hasModule('appointments'))
            @can('appointments.manage')
                <a href="{{ route('tenant.appointments.index', ['book' => 1]) }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-calendar-event-line me-1"></i>Book Appointment</a>
            @endcan
        @endif
    </x-page-header>

    @if (hospital()->status === 'trial' && hospital()->trial_ends_at)
        <div class="alert alert-info py-2"><i class="ri-information-line me-1"></i> Trial ends on {{ fmt_date(hospital()->trial_ends_at) }}.
            @can('subscription.manage')<a href="{{ route('tenant.subscription') }}" wire:navigate class="alert-link">Manage subscription</a>@endcan
        </div>
    @endif

    <div class="row g-4 mb-4">
        @foreach ($cards as $card)
            <div class="col-sm-6 col-xl-3">
                <x-stat-card :title="$card['title']" :value="$card['value']" :icon="$card['icon']" :color="$card['color']" :hint="$card['hint'] ?? null" :href="$card['href'] ?? null" />
            </div>
        @endforeach
    </div>

    @if ($charts)
        <div class="row g-4 mb-4">
            @isset($charts['revenue'])
                <div class="col-xl-{{ isset($charts['opd']) ? 6 : 12 }}">
                    <div class="card h-100 mb-0"><div class="card-header"><h5 class="card-title mb-0">Collections – last 14 days</h5></div>
                        <div class="card-body"><div x-data="apexChart(@js($charts['revenue']))" wire:ignore></div></div></div>
                </div>
            @endisset
            @isset($charts['opd'])
                <div class="col-xl-{{ isset($charts['revenue']) ? 6 : 12 }}">
                    <div class="card h-100 mb-0"><div class="card-header"><h5 class="card-title mb-0">OPD visits – last 14 days</h5></div>
                        <div class="card-body"><div x-data="apexChart(@js($charts['opd']))" wire:ignore></div></div></div>
                </div>
            @endisset
        </div>
    @endif

    <div class="row g-4">
        @if ($appointments->isNotEmpty() || hospital()->hasModule('appointments'))
            <div class="col-xl-7">
                <div class="card mb-0 h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ $doctor ? 'My' : "Today's" }} appointments</h5>
                        @can('appointments.view')<a href="{{ route('tenant.appointments.index') }}" wire:navigate class="btn btn-sm btn-light-primary">View all</a>@endcan
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hms table-hover mb-0">
                            <thead><tr><th>Time</th><th>Token</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
                            <tbody>
                                @forelse ($appointments as $a)
                                    <tr>
                                        <td>{{ fmt_time($a->start_time) }}</td>
                                        <td>{{ $a->token_no ? '#'.$a->token_no : '—' }}</td>
                                        <td><a href="{{ route('tenant.patients.show', $a->patient) }}" wire:navigate>{{ $a->patient->full_name }}</a>
                                            @if ($a->isVideo())<i class="ri-video-chat-line text-info ms-1" title="Video consultation"></i>@endif</td>
                                        <td>{{ $a->doctor->display_name }}</td>
                                        <td><x-status :value="$a->status" /></td>
                                    </tr>
                                @empty
                                    <x-empty-row :colspan="5" message="No appointments today." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif
        @if ($recentPatients->isNotEmpty())
            <div class="col-xl-5">
                <div class="card mb-0 h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Recently registered</h5>
                        <a href="{{ route('tenant.patients.index') }}" wire:navigate class="btn btn-sm btn-light-primary">All patients</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @foreach ($recentPatients as $p)
                            <li class="list-group-item d-flex align-items-center gap-3">
                                <x-avatar :src="$p->photoUrl()" :name="$p->full_name" />
                                <div class="flex-grow-1 min-w-0">
                                    <a href="{{ route('tenant.patients.show', $p) }}" wire:navigate class="fw-semibold d-block text-truncate">{{ $p->full_name }}</a>
                                    <small class="text-muted">{{ $p->uhid }} &middot; {{ $p->age_gender }}</small>
                                </div>
                                <small class="text-muted">{{ $p->created_at->diffForHumans() }}</small>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>
