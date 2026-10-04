<?php

use App\Models\Appointment;
use App\Services\Telemedicine\VideoRoom;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('Video Consultation')] class extends Component
{
    public Appointment $appointment;

    public array $config = [];

    public function mount(Appointment $appointment, VideoRoom $video): void
    {
        // Patients may only join their own video appointments.
        abort_unless($appointment->patient_id === auth()->user()->patient?->id && $appointment->mode === 'video', 404);
        abort_unless(hospital()->hasModule('telemedicine'), 403);
        abort_if(in_array($appointment->status, ['cancelled', 'completed', 'no_show']), 410, 'This consultation is no longer active.');
        $this->appointment = $appointment;
        $this->config = $video->joinConfig($appointment, auth()->user()->name);
    }
}; ?>

<div>
    <x-page-header :title="'Video consultation · '.$appointment->doctor->display_name" :subtitle="fmt_date($appointment->appointment_date).' '.fmt_time($appointment->start_time)" />
    <div class="card mb-0">
        <div class="card-body p-0" wire:ignore>
            @if ($config['driver'] === 'jitsi')
                <div style="height: 72vh;" class="rounded overflow-hidden"
                    x-data x-init="
                        const cfg = @js($config);
                        const boot = () => { $el._jitsi = new JitsiMeetExternalAPI(cfg.domain, { roomName: cfg.room, parentNode: $el, width: '100%', height: '100%', userInfo: { displayName: cfg.displayName } }); };
                        if (window.JitsiMeetExternalAPI) { boot(); } else { const s = document.createElement('script'); s.src = 'https://' + cfg.domain + '/external_api.js'; s.onload = boot; document.head.appendChild(s); }"
                    x-on:livewire:navigating.window="$el._jitsi && $el._jitsi.dispose()"></div>
            @else
                <div class="p-5 text-center text-muted">Your doctor will start the call shortly. Channel: {{ $config['channel'] }}</div>
            @endif
        </div>
    </div>
    <p class="text-muted fs-13 mt-3">Allow camera and microphone access when prompted. If the doctor has not joined yet, please wait in the room.</p>
</div>
