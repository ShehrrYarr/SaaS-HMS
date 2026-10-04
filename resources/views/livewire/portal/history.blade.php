<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('Medical History')] class extends Component
{
    public function with(): array
    {
        $p = auth()->user()->patient->load(['allergies', 'histories']);
        $vitals = $p->vitals()->latest('recorded_at')->limit(20)->get()->sortBy('recorded_at')->values();

        return [
            'p' => $p,
            'diagnoses' => $p->diagnoses()->latest()->get(),
            'visits' => hospital()->hasModule('opd') ? $p->opdVisits()->with('doctor')->latest('visit_date')->limit(20)->get() : collect(),
            'admissions' => hospital()->hasModule('ipd') ? $p->admissions()->with('doctor')->latest('admitted_at')->get() : collect(),
            'vitals' => $vitals,
            'chart' => $vitals->count() > 1 ? [
                'chart' => ['type' => 'line', 'height' => 240],
                'series' => [['name' => 'Systolic', 'data' => $vitals->pluck('bp_systolic')], ['name' => 'Diastolic', 'data' => $vitals->pluck('bp_diastolic')], ['name' => 'Weight', 'data' => $vitals->pluck('weight')]],
                'xaxis' => ['categories' => $vitals->map(fn ($v) => $v->recorded_at->format('d M'))],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
            ] : null,
        ];
    }
}; ?>

<div>
    <x-page-header title="Medical History" subtitle="My health record" />
    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Allergies</h6></div>
                <div class="card-body">@forelse ($p->allergies as $a)<span class="badge bg-danger-subtle text-danger me-1 mb-1">{{ $a->allergen }} ({{ $a->severity }})</span>@empty<span class="text-muted">None recorded</span>@endforelse</div>
            </div>
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Conditions &amp; history</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($p->histories as $h)<li class="list-group-item"><span class="badge bg-light text-body me-1">{{ label($h->type) }}</span>{{ $h->title }}</li>@empty<li class="list-group-item text-muted">None recorded</li>@endforelse
                </ul>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Diagnoses</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($diagnoses as $d)<li class="list-group-item fs-13">{{ $d->icd_code ? $d->icd_code.' – ' : '' }}{{ $d->description }} <span class="text-muted">· {{ fmt_date($d->created_at) }}</span></li>@empty<li class="list-group-item text-muted">None</li>@endforelse
                </ul>
            </div>
        </div>
        <div class="col-xl-8">
            @if ($chart)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Vitals trend</h6></div><div class="card-body"><div x-data="apexChart(@js($chart))" wire:ignore></div></div></div>
            @endif
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Visits</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($visits as $v)<li class="list-group-item d-flex justify-content-between"><span>{{ fmt_date($v->visit_date) }} · {{ $v->doctor->display_name }}</span><span class="text-muted fs-13">{{ $v->chief_complaint }}</span></li>@empty<li class="list-group-item text-muted">No OPD visits.</li>@endforelse
                </ul>
            </div>
            @if ($admissions->isNotEmpty())
                <div class="card mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">Hospital stays</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($admissions as $a)<li class="list-group-item">{{ fmt_date($a->admitted_at) }} – {{ $a->discharged_at ? fmt_date($a->discharged_at) : 'current' }} · {{ $a->doctor->display_name }} · {{ $a->reason }}</li>@endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
