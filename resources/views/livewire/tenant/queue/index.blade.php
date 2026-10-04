<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\Toasts;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\OpdService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('OPD Queue')] class extends Component
{
    use SearchesPatients, Toasts;

    public ?string $patient_id = null;

    public ?string $doctor_id = null;

    public string $complaint = '';

    public function issueToken(OpdService $opd): void
    {
        abort_unless(auth()->user()->canAny(['queue.manage', 'opd.create']), 403);
        $this->validate(['patient_id' => ['required', tenant_exists('patients')], 'doctor_id' => ['required', doctor_exists()], 'complaint' => 'nullable|string|max:200'], [], ['patient_id' => 'patient', 'doctor_id' => 'doctor']);

        $visit = $opd->createVisit(Patient::findOrFail($this->patient_id), Staff::doctors()->findOrFail($this->doctor_id), ['chief_complaint' => $this->complaint]);
        $this->reset('patient_id', 'complaint');
        $this->toast("Token #{$visit->token_no} issued for {$visit->patient->full_name}");
        $this->dispatch('print', url: route('tenant.opd.slip', $visit->id));
    }

    public function callNext(int $doctorId, OpdService $opd): void
    {
        $this->authorize('queue.manage');
        $next = OpdVisit::where('doctor_id', $doctorId)->whereDate('visit_date', today())->where('status', 'waiting')->orderBy('token_no')->first();
        if (! $next) {
            $this->toast('No patients waiting.', 'info');

            return;
        }
        $opd->startConsultation($next);
        $this->toast("Now serving token #{$next->token_no}");
    }

    public function setStatus(int $visitId, string $status, OpdService $opd): void
    {
        $this->authorize('queue.manage');
        $visit = OpdVisit::findOrFail($visitId);
        match ($status) {
            'in_consultation' => $opd->startConsultation($visit),
            'completed' => $opd->completeConsultation($visit),
            'waiting' => $visit->update(['status' => 'waiting']),
            'cancelled' => $visit->update(['status' => 'cancelled']),
            default => abort(400),
        };
    }

    public function regenerateDisplayKey(): void
    {
        $this->authorize('queue.manage');
        $h = hospital();
        $h->update(['settings' => array_merge($h->settings ?? [], ['queue_display_key' => Str::random(24)])]);
        $this->toast('Display link regenerated.');
    }

    public function with(): array
    {
        $visits = OpdVisit::with('patient')->whereDate('visit_date', today())->whereNot('status', 'cancelled')->orderBy('token_no')->get()->groupBy('doctor_id');
        $doctors = Staff::doctors()->active()->orderBy('name')->get()
            ->filter(fn ($d) => $visits->has($d->id) || $d->isAvailableOn(today()))
            ->values();

        return [
            'doctors' => $doctors,
            'visits' => $visits,
            'doctorOptions' => $this->doctorOptions(),
            'displayUrl' => route('tenant.queue.display', ['key' => hospital()->setting('queue_display_key')]),
        ];
    }
}; ?>

<div wire:poll.10s>
    <x-page-header title="OPD Queue & Tokens" :subtitle="'Today · '.now()->format('d M Y')">
        @if (hospital()->setting('queue_display_key'))
            <a href="{{ $displayUrl }}" target="_blank" class="btn btn-light-info btn-sm"><i class="ri-tv-2-line me-1"></i>Open TV display</a>
        @endif
        <button class="btn btn-light btn-sm" x-on:click="$confirm('Generate a new display link? Existing screens will stop updating.', () => $wire.regenerateDisplayKey(), { color: 'warning' })" title="Regenerate display link"><i class="ri-refresh-line"></i></button>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body row g-2 align-items-end">
            <x-form.search-select class="col-md-5 mb-0" label="Patient" model="patient_id" search="searchPatients" placeholder="Search patient (walk-in token)" />
            <x-form.search-select class="col-md-3 mb-0" label="Doctor" model="doctor_id" :options="$doctorOptions" />
            <x-form.input class="col-md-2 mb-0" label="Complaint" model="complaint" />
            <div class="col-md-2"><button class="btn btn-primary w-100" wire:click="issueToken" wire:loading.attr="disabled"><i class="ri-coupon-3-line me-1"></i>Issue token</button></div>
            <div class="col-12 fs-12 text-muted">New patient? <a href="{{ route('tenant.patients.index') }}" wire:navigate>Quick-register</a> first, then issue the token. Tokens are numbered per doctor per day.</div>
        </div>
    </div>

    <div class="row g-4">
        @forelse ($doctors as $doctor)
            @php
                $list = $visits->get($doctor->id, collect());
                $current = $list->firstWhere('status', 'in_consultation');
                $waiting = $list->where('status', 'waiting');
                $done = $list->where('status', 'completed')->count();
            @endphp
            <div class="col-md-6 col-xl-4" wire:key="qd-{{ $doctor->id }}">
                <div class="card h-100 mb-0">
                    <div class="card-header d-flex align-items-center gap-2">
                        <x-avatar :src="$doctor->photoUrl()" :name="$doctor->name" />
                        <div class="flex-grow-1 min-w-0"><h6 class="mb-0 text-truncate">{{ $doctor->display_name }}</h6><small class="text-muted">{{ $doctor->specialization }}</small></div>
                        <span class="badge bg-success-subtle text-success">{{ $done }} done</span>
                    </div>
                    <div class="card-body">
                        <div class="text-center bg-primary-subtle rounded p-3 mb-3">
                            <div class="text-muted fs-12 text-uppercase">Now serving</div>
                            <div class="token-display text-primary">{{ $current ? '#'.$current->token_no : '—' }}</div>
                            <div class="fs-13">{{ $current?->patient->full_name }}</div>
                            @if ($current)
                                <div class="mt-2 d-flex justify-content-center gap-1">
                                    <button class="btn btn-sm btn-success" wire:click="setStatus({{ $current->id }}, 'completed')">Done</button>
                                    <button class="btn btn-sm btn-light" wire:click="setStatus({{ $current->id }}, 'waiting')">Back to queue</button>
                                </div>
                            @endif
                        </div>
                        <button class="btn btn-primary w-100 mb-3" wire:click="callNext({{ $doctor->id }})" @disabled($waiting->isEmpty())>
                            <i class="ri-megaphone-line me-1"></i> Call next ({{ $waiting->count() }} waiting)
                        </button>
                        <ul class="list-group list-group-flush" style="max-height: 260px; overflow-y: auto;">
                            @forelse ($waiting as $v)
                                <li class="list-group-item d-flex align-items-center gap-2 px-0">
                                    <span class="badge bg-light text-body fs-13">#{{ $v->token_no }}</span>
                                    <div class="flex-grow-1 min-w-0"><div class="text-truncate">{{ $v->patient->full_name }}</div><small class="text-muted">{{ $v->visit_date->format('h:i A') }} · {{ label($v->visit_type) }}</small></div>
                                    <button class="btn btn-sm btn-light-primary icon-btn-sm" title="Call" wire:click="setStatus({{ $v->id }}, 'in_consultation')"><i class="ri-arrow-right-line"></i></button>
                                    <button class="btn btn-sm btn-light-danger icon-btn-sm" title="Remove" x-on:click="$confirm('Remove token #{{ $v->token_no }}?', () => $wire.setStatus({{ $v->id }}, 'cancelled'))"><i class="ri-close-line"></i></button>
                                </li>
                            @empty
                                <li class="list-group-item text-muted text-center fs-13 px-0">Queue empty</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info">No doctors are scheduled today.</div></div>
        @endforelse
    </div>
</div>
