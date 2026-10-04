<?php

use App\Models\Appointment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Telemedicine')] class extends Component
{
    public function with(): array
    {
        $doctor = auth()->user()->staff?->staff_type === 'doctor' ? auth()->user()->staff : null;
        $base = Appointment::with(['patient', 'doctor'])->where('mode', 'video')->when($doctor, fn ($q) => $q->where('doctor_id', $doctor->id));

        return [
            'today' => (clone $base)->whereDate('appointment_date', today())->whereNotIn('status', ['cancelled', 'no_show'])->orderBy('start_time')->get(),
            'upcoming' => (clone $base)->whereDate('appointment_date', '>', today())->whereIn('status', ['booked', 'confirmed'])->orderBy('appointment_date')->orderBy('start_time')->limit(20)->get(),
            'driver' => config('hms.telemedicine.driver'),
        ];
    }
}; ?>

<div wire:poll.30s>
    <x-page-header title="Telemedicine" subtitle="Video consultations">
        @can('appointments.manage')<a href="{{ route('tenant.appointments.index', ['book' => 1]) }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i>Book video consult</a>@endcan
    </x-page-header>
    <div class="alert alert-info py-2 fs-13"><i class="ri-information-line"></i> Provider: <strong>{{ ucfirst($driver) }}</strong>. Patients join from the Patient Portal; doctors join from here. Rooms are private and unique per appointment.</div>
    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Today</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($today as $a)
                        <li class="list-group-item d-flex align-items-center gap-3">
                            <div class="fw-bold" style="width: 80px;">{{ fmt_time($a->start_time) }}</div>
                            <div class="flex-grow-1"><strong>{{ $a->patient->full_name }}</strong> <span class="text-muted fs-12">{{ $a->patient->uhid }}</span><div class="fs-12 text-muted">{{ $a->doctor->display_name }} · {{ $a->reason }}</div></div>
                            <x-status :value="$a->status" />
                            @if (! in_array($a->status, ['completed']))<a href="{{ route('tenant.telemedicine.room', $a) }}" wire:navigate class="btn btn-sm btn-info"><i class="ri-video-chat-line"></i> Join</a>@endif
                        </li>
                    @empty
                        <li class="list-group-item text-muted text-center py-4">No video consultations today.</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Upcoming</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($upcoming as $a)
                        <li class="list-group-item"><strong>{{ fmt_date($a->appointment_date, 'D d M') }} {{ fmt_time($a->start_time) }}</strong> · {{ $a->patient->full_name }}<div class="fs-12 text-muted">{{ $a->doctor->display_name }}</div></li>
                    @empty
                        <li class="list-group-item text-muted">None.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
