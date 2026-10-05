<?php

use App\Models\IpdAdmission;
use App\Models\Ward;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Nurse Station')] class extends Component
{
    #[Url]
    public string $ward = '';

    public ?int $selected = null;

    public bool $showVitals = false;

    public function record(int $admissionId): void
    {
        $this->selected = $admissionId;
        $this->showVitals = true;
    }

    #[On('vitals-saved')]
    public function refreshAfterVitals(): void
    {
        // re-render to update alerts
    }

    public function with(): array
    {
        $admissions = IpdAdmission::with(['patient.allergies', 'bed.ward', 'doctor'])
            ->with(['vitals' => fn ($q) => $q->latest('recorded_at')->limit(1)])
            ->where('status', 'admitted')
            ->when($this->ward, fn ($q) => $q->whereHas('bed', fn ($b) => $b->where('ward_id', $this->ward)))
            ->get()
            ->map(function (IpdAdmission $a) {
                $v = $a->vitals->first();
                $alerts = [];
                if ($v) {
                    if ($v->spo2 && $v->spo2 < 94) $alerts[] = 'SpO₂ '.$v->spo2.'%';
                    if ($v->temperature && $v->temperature >= 38) $alerts[] = 'Fever '.$v->temperature.'°C';
                    if ($v->bp_systolic && ($v->bp_systolic >= 160 || $v->bp_systolic < 90)) $alerts[] = 'BP '.$v->bp;
                    if ($v->pulse && ($v->pulse > 120 || $v->pulse < 50)) $alerts[] = 'Pulse '.$v->pulse;
                    if ($v->temperature && $v->temperature < 35) $alerts[] = 'Low temp '.$v->temperature.'°C';
                    if ($v->respiratory_rate && ($v->respiratory_rate > 24 || $v->respiratory_rate < 10)) $alerts[] = 'RR '.$v->respiratory_rate;
                    if ($v->blood_sugar && ($v->blood_sugar < 70 || $v->blood_sugar > 300)) $alerts[] = 'Sugar '.$v->blood_sugar.' mg/dL';
                }
                $a->setAttribute('vital_alerts', $alerts);
                $a->setAttribute('vitals_due', ! $v || $v->recorded_at->lt(now()->subHours(4)));

                return $a;
            })
            ->sortByDesc(fn ($a) => count($a->vital_alerts) * 10 + ($a->vitals_due ? 1 : 0));

        return [
            'admissions' => $admissions,
            'wards' => Ward::orderBy('name')->pluck('name', 'id'),
            'current' => $this->selected ? IpdAdmission::with('patient')->find($this->selected) : null,
        ];
    }
}; ?>

<div wire:poll.30s>
    <x-page-header title="Nurse Station" subtitle="Vitals monitoring" :breadcrumbs="['IPD' => route('tenant.ipd.index')]">
        <select class="form-select form-select-sm w-auto" wire:model.live="ward"><option value="">All wards</option>@foreach ($wards as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
    </x-page-header>

    <div class="row g-3">
        @forelse ($admissions as $a)
            @php $v = $a->vitals->first(); @endphp
            <div class="col-md-6 col-xl-4" wire:key="ns-{{ $a->id }}">
                <div class="card h-100 mb-0 {{ count($a->vital_alerts) ? 'border-danger' : ($a->vitals_due ? 'border-warning' : '') }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="mb-0"><a href="{{ route('tenant.ipd.show', $a) }}" wire:navigate>{{ $a->patient->full_name }}</a></h6>
                                <small class="text-muted">{{ $a->bed?->label }} · {{ $a->patient->age_gender }} · Day {{ $a->lengthOfStay() }}</small>
                            </div>
                            <button class="btn btn-sm btn-primary" wire:click="record({{ $a->id }})"><i class="ri-heart-pulse-line"></i> Vitals</button>
                        </div>
                        @if ($a->patient->allergies->isNotEmpty())<div class="fs-12 text-danger mt-1"><i class="ri-alarm-warning-line"></i> {{ $a->patient->allergies->pluck('allergen')->join(', ') }}</div>@endif
                        <div class="row g-1 text-center fs-12 mt-2">
                            <div class="col"><div class="text-muted">BP</div><strong>{{ $v?->bp ?? '—' }}</strong></div>
                            <div class="col"><div class="text-muted">Pulse</div><strong>{{ $v?->pulse ?? '—' }}</strong></div>
                            <div class="col"><div class="text-muted">Temp</div><strong>{{ $v?->temperature ?? '—' }}</strong></div>
                            <div class="col"><div class="text-muted">SpO₂</div><strong>{{ $v?->spo2 ?? '—' }}</strong></div>
                            <div class="col"><div class="text-muted">RR</div><strong>{{ $v?->respiratory_rate ?? '—' }}</strong></div>
                        </div>
                        <div class="mt-2">
                            @foreach ($a->vital_alerts as $alert)<span class="badge bg-danger me-1">{{ $alert }}</span>@endforeach
                            @if ($a->vitals_due)<span class="badge bg-warning-subtle text-warning">Vitals due{{ $v ? ' · last '.$v->recorded_at->diffForHumans() : '' }}</span>@endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info">No admitted patients.</div></div>
        @endforelse
    </div>

    <x-modal wire:model="showVitals" :title="'Record vitals · '.($current?->patient->full_name ?? '')" size="xl">
        @if ($current)
            <livewire:tenant.emr.vitals :patient="$current->patient" :visitable="$current" :compact="true" :key="'nsv-'.$current->id" />
        @endif
    </x-modal>
</div>
