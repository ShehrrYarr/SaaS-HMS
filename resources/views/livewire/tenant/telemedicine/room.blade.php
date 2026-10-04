<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Appointment;
use App\Services\OpdService;
use App\Services\Telemedicine\VideoRoom;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Video Consultation')] class extends Component
{
    use Toasts;

    public Appointment $appointment;

    public array $config = [];

    public function mount(Appointment $appointment, VideoRoom $video): void
    {
        abort_unless($appointment->mode === 'video', 404);
        $this->appointment = $appointment;
        $this->config = $video->joinConfig($appointment, auth()->user()->staff?->display_name ?? auth()->user()->name);
    }

    public function startVisit(OpdService $opd)
    {
        $visit = $this->appointment->opdVisit ?? $opd->checkIn($this->appointment);
        $opd->startConsultation($visit);
        $this->toast('Consultation opened in a new tab.');
        $this->dispatch('print', url: route('tenant.opd.consult', $visit), title: 'Open consultation notes');
    }

    public function with(): array
    {
        return ['a' => $this->appointment->load(['patient.allergies', 'doctor', 'opdVisit'])];
    }
}; ?>

<div>
    <x-page-header :title="'Video consult · '.$a->patient->full_name" :subtitle="fmt_date($a->appointment_date).' '.fmt_time($a->start_time)" :breadcrumbs="['Telemedicine' => route('tenant.telemedicine.index')]">
        @if (hospital()->hasModule('opd'))
            @can('opd.consult')<button class="btn btn-sm btn-primary" wire:click="startVisit"><i class="ri-stethoscope-line me-1"></i>Notes &amp; e-prescription</button>@endcan
        @endif
    </x-page-header>
    <div class="row g-4">
        <div class="col-xl-9">
            <div class="card mb-0">
                <div class="card-body p-0" wire:ignore>
                    @if ($config['driver'] === 'jitsi')
                        <div id="jitsi-container" style="height: 70vh;" class="rounded overflow-hidden"
                            x-data x-init="
                                const cfg = @js($config);
                                const boot = () => {
                                    const api = new JitsiMeetExternalAPI(cfg.domain, { roomName: cfg.room, parentNode: $el, width: '100%', height: '100%', userInfo: { displayName: cfg.displayName }, configOverwrite: { prejoinPageEnabled: true } });
                                    $el._jitsi = api;
                                };
                                if (window.JitsiMeetExternalAPI) { boot(); } else {
                                    const s = document.createElement('script'); s.src = 'https://' + cfg.domain + '/external_api.js'; s.onload = boot; document.head.appendChild(s);
                                }"
                            x-on:livewire:navigating.window="$el._jitsi && $el._jitsi.dispose()"></div>
                    @else
                        <div class="p-5 text-center">
                            <i class="ri-video-chat-line fs-1 text-primary"></i>
                            <h5 class="mt-3">Agora channel: {{ $config['channel'] }}</h5>
                            <p class="text-muted">Agora is configured as the provider. Add the Agora Web SDK and token server (see README → Telemedicine) to enable in-page video.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-3">
            <div class="card mb-0">
                <div class="card-body fs-13">
                    <h6>{{ $a->patient->full_name }}</h6>
                    <p class="text-muted">{{ $a->patient->uhid }} · {{ $a->patient->age_gender }}</p>
                    <p><strong>Reason:</strong> {{ $a->reason ?: '—' }}</p>
                    <p><strong>Allergies:</strong> {{ $a->patient->allergies->pluck('allergen')->join(', ') ?: 'NKA' }}</p>
                    <a href="{{ route('tenant.patients.show', $a->patient) }}" target="_blank" class="btn btn-sm btn-light w-100">Open EMR in new tab</a>
                </div>
            </div>
        </div>
    </div>
</div>
