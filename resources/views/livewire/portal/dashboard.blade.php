<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Health')] class extends Component
{
    public function with(): array
    {
        $p = auth()->user()->patient->load(['allergies', 'latestVital', 'currentAdmission.bed.ward']);
        $h = hospital();

        return [
            'p' => $p,
            'next' => $h->hasModule('appointments') ? $p->appointments()->with('doctor')->whereDate('appointment_date', '>=', today())->whereIn('status', ['booked', 'confirmed', 'checked_in'])->orderBy('appointment_date')->orderBy('start_time')->first() : null,
            'reports' => $h->hasModule('laboratory') ? $p->labOrders()->where('status', 'approved')->latest('approved_at')->limit(3)->get() : collect(),
            'prescriptions' => $h->hasModule('opd') ? $p->prescriptions()->with('doctor')->latest()->limit(3)->get() : collect(),
            'due' => $h->hasModule('billing') ? $p->invoices()->whereIn('status', ['unpaid', 'partial'])->get()->sum('balance') : 0,
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Hello, '.$p->first_name" :subtitle="$p->uhid.' · '.hospital()->name" />

    <div class="row g-4 mb-4">
        <div class="col-md-6 col-xl-3">
            <x-stat-card title="Next appointment" :value="$next ? fmt_date($next->appointment_date, 'D d M') : 'None'" icon="ri-calendar-check-line" color="primary"
                :hint="$next ? fmt_time($next->start_time).' · '.$next->doctor->display_name.($next->isVideo() ? ' · video' : '') : 'Book a visit online'" :href="hospital()->hasModule('appointments') ? route('portal.appointments') : null" />
        </div>
        <div class="col-md-6 col-xl-3"><x-stat-card title="Reports ready" :value="$reports->count()" icon="ri-flask-line" color="success" :href="hospital()->hasModule('laboratory') ? route('portal.lab-reports') : null" /></div>
        <div class="col-md-6 col-xl-3"><x-stat-card title="Prescriptions" :value="$prescriptions->count()" icon="ri-capsule-line" color="info" :href="hospital()->hasModule('opd') ? route('portal.prescriptions') : null" /></div>
        <div class="col-md-6 col-xl-3"><x-stat-card title="Amount due" :value="money($due)" icon="ri-bill-line" :color="$due > 0 ? 'danger' : 'success'" :href="hospital()->hasModule('billing') ? route('portal.bills') : null" /></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-body text-center">
                    <x-avatar :src="$p->photoUrl()" :name="$p->full_name" size="xl" class="mb-3" />
                    <h5 class="mb-1">{{ $p->full_name }}</h5>
                    <p class="text-muted mb-2">{{ $p->uhid }} · {{ $p->age_gender }} · {{ $p->blood_group ?: 'Blood group ?' }}</p>
                    @if ($p->currentAdmission)<div class="alert alert-warning py-2">Currently admitted · {{ $p->currentAdmission->bed?->label }}</div>@endif
                    <div class="text-start fs-13">
                        <strong>Allergies:</strong> {{ $p->allergies->pluck('allergen')->join(', ') ?: 'None recorded' }}
                        @if ($v = $p->latestVital)<div class="mt-2"><strong>Last vitals ({{ fmt_date($v->recorded_at) }}):</strong> BP {{ $v->bp ?? '—' }}, pulse {{ $v->pulse ?? '—' }}, weight {{ $v->weight ?? '—' }} kg</div>@endif
                    </div>
                    <a href="{{ route('portal.profile') }}" wire:navigate class="btn btn-sm btn-light-primary mt-3">Update my details</a>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            @if ($next && $next->isVideo() && hospital()->hasModule('telemedicine'))
                <div class="alert alert-info d-flex justify-content-between align-items-center">
                    <span><i class="ri-video-chat-line me-1"></i>Video consultation {{ fmt_date($next->appointment_date, 'D d M') }} at {{ fmt_time($next->start_time) }} with {{ $next->doctor->display_name }}</span>
                    @if ($next->appointment_date->isToday())<a href="{{ route('portal.video', $next) }}" wire:navigate class="btn btn-sm btn-info">Join</a>@endif
                </div>
            @endif
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Latest reports</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($reports as $r)
                        <li class="list-group-item d-flex justify-content-between align-items-center"><span>{{ $r->order_no }} · {{ fmt_date($r->approved_at) }}</span><a href="{{ route('portal.lab-report.pdf', $r->id) }}" target="_blank" class="btn btn-sm btn-light-success"><i class="ri-download-2-line"></i> PDF</a></li>
                    @empty
                        <li class="list-group-item text-muted">No reports yet.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Recent prescriptions</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($prescriptions as $rx)
                        <li class="list-group-item d-flex justify-content-between align-items-center"><span>{{ $rx->prescription_no }} · {{ $rx->doctor->display_name }} · {{ fmt_date($rx->created_at) }}</span><a href="{{ route('portal.prescription.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light-primary"><i class="ri-download-2-line"></i></a></li>
                    @empty
                        <li class="list-group-item text-muted">No prescriptions yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
