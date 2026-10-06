<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Appointment;
use App\Models\Staff;
use App\Services\AppointmentService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Appointments')] class extends Component
{
    use Toasts;

    public ?string $doctor_id = null;

    public string $date = '';

    public ?string $slot = null;

    public string $mode = 'in_person';

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(hospital()->hasModule('appointments'), 403);
        $this->date = today()->addDay()->toDateString();
    }

    public function updatedDoctorId(): void
    {
        $this->slot = null;
    }

    public function updatedDate(): void
    {
        $this->slot = null;
    }

    public function book(AppointmentService $service): void
    {
        $this->validate([
            'doctor_id' => ['required', doctor_exists()],
            'date' => 'required|date|after_or_equal:today|before:+60 days',
            'slot' => 'required|date_format:H:i',
            'mode' => 'required|in:in_person,video',
            'reason' => 'nullable|string|max:200',
        ], [], ['slot' => 'time slot', 'doctor_id' => 'doctor']);

        if ($this->mode === 'video' && ! hospital()->hasModule('telemedicine')) {
            $this->addError('mode', 'Video consultations are not available.');

            return;
        }

        // A patient account cannot fill a doctor's diary: at most 5 portal bookings an hour.
        if (! RateLimiter::attempt('portal-book:'.auth()->id(), 5, fn () => true, 3600)) {
            $this->addError('slot', 'You have made several bookings recently. Please call the hospital to book more.');

            return;
        }
        $patient = auth()->user()->patient;
        $doctor = Staff::doctors()->active()->findOrFail($this->doctor_id);
        $appointment = $service->book($patient, $doctor, [
            'appointment_date' => $this->date, 'start_time' => $this->slot, 'mode' => $this->mode,
            'source' => 'portal', 'reason' => $this->reason ?: null,
        ]);
        $this->reset('slot', 'reason');
        $this->toast("Booked {$appointment->appointment_no} on ".fmt_date($appointment->appointment_date).' at '.fmt_time($appointment->start_time).'.');
    }

    public function cancel(int $id): void
    {
        $a = auth()->user()->patient->appointments()->whereIn('status', ['booked', 'confirmed'])->findOrFail($id);
        abort_if($a->appointment_date->isPast() && ! $a->appointment_date->isToday(), 422);
        $a->update(['status' => 'cancelled', 'cancel_reason' => 'Cancelled by patient via portal']);
        $this->toast('Appointment cancelled.', 'warning');
    }

    public function with(AppointmentService $service): array
    {
        $patient = auth()->user()->patient;
        $doctor = $this->doctor_id ? Staff::doctors()->active()->find($this->doctor_id) : null;

        return [
            'doctors' => Staff::doctors()->active()->with('department')->orderBy('name')->get(),
            'slots' => $doctor && $this->date ? $service->availableSlots($doctor, $this->date) : [],
            'doctor' => $doctor,
            'upcoming' => $patient->appointments()->with('doctor')->whereDate('appointment_date', '>=', today())->whereNotIn('status', ['cancelled', 'completed', 'no_show'])->orderBy('appointment_date')->orderBy('start_time')->get(),
            'past' => $patient->appointments()->with('doctor')->where(fn ($q) => $q->whereDate('appointment_date', '<', today())->orWhereIn('status', ['cancelled', 'completed', 'no_show']))->latest('appointment_date')->limit(15)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="My Appointments" subtitle="Book & manage" />
    <div class="row g-4">
        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Book an appointment</h6></div>
                <div class="card-body">
                    <label class="form-label">Doctor</label>
                    <select class="form-select mb-3 @error('doctor_id') is-invalid @enderror" wire:model.live="doctor_id">
                        <option value="">Choose a doctor…</option>
                        @foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->display_name }} — {{ $d->specialization ?? $d->department?->name }} ({{ money($d->consultation_fee) }})</option>@endforeach
                    </select>
                    <x-form.input label="Date" model="date" type="date" live :min="today()->toDateString()" />
                    @if ($doctor)
                        <label class="form-label">Available times</label>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            @forelse ($slots as $value => $text)
                                <input type="radio" class="btn-check" id="ps-{{ str_replace(':', '', $value) }}" value="{{ $value }}" wire:model="slot">
                                <label class="btn btn-sm btn-outline-primary" for="ps-{{ str_replace(':', '', $value) }}">{{ $text }}</label>
                            @empty
                                <span class="text-muted fs-13">No free slots on this day. Try another date.</span>
                            @endforelse
                        </div>
                        @error('slot')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
                    @endif
                    @if (hospital()->hasModule('telemedicine'))
                        <div class="btn-group w-100 mb-3">
                            <input type="radio" class="btn-check" id="m-in" value="in_person" wire:model="mode"><label class="btn btn-outline-secondary" for="m-in"><i class="ri-hospital-line"></i> In person</label>
                            <input type="radio" class="btn-check" id="m-vid" value="video" wire:model="mode"><label class="btn btn-outline-secondary" for="m-vid"><i class="ri-video-chat-line"></i> Video</label>
                        </div>
                    @endif
                    <x-form.input label="Reason for visit" model="reason" />
                    <button class="btn btn-primary w-100" wire:click="book" wire:loading.attr="disabled">Confirm booking</button>
                </div>
            </div>
        </div>
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Upcoming</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($upcoming as $a)
                        <li class="list-group-item d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <span><strong>{{ fmt_date($a->appointment_date, 'D d M Y') }} · {{ fmt_time($a->start_time) }}</strong><div class="fs-13 text-muted">{{ $a->doctor->display_name }} · {{ $a->appointment_no }} @if ($a->token_no)· Token #{{ $a->token_no }}@endif</div></span>
                            <span>
                                <x-status :value="$a->status" />
                                @if ($a->isVideo())
                                    @if ($a->appointment_date->isToday())<a href="{{ route('portal.video', $a) }}" wire:navigate class="btn btn-sm btn-info ms-1"><i class="ri-video-chat-line"></i> Join</a>@else<span class="badge bg-info-subtle text-info">Video</span>@endif
                                @endif
                                @if (in_array($a->status, ['booked', 'confirmed']))<button class="btn btn-sm btn-light-danger ms-1" x-on:click="$confirm('Cancel this appointment?', () => $wire.cancel({{ $a->id }}))">Cancel</button>@endif
                            </span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No upcoming appointments.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">History</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($past as $a)
                        <li class="list-group-item d-flex justify-content-between"><span>{{ fmt_date($a->appointment_date) }} · {{ $a->doctor->display_name }}</span><x-status :value="$a->status" /></li>
                    @empty
                        <li class="list-group-item text-muted">No past appointments.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
