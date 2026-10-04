<?php

use App\Livewire\Concerns\Toasts;
use App\Models\DoctorLeave;
use App\Models\DoctorSchedule;
use App\Models\Staff;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Doctor Schedules')] class extends Component
{
    use Toasts;

    #[Url]
    public ?string $doctorId = null;

    public array $form = ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'slot_minutes' => 15, 'max_patients' => '', 'room' => ''];

    public array $copyDays = [];

    public string $leaveDate = '';

    public string $leaveReason = '';

    public function mount(): void
    {
        $this->doctorId ??= (string) Staff::doctors()->active()->orderBy('name')->value('id');
    }

    public function add(): void
    {
        $this->authorize('schedules.manage');
        $data = $this->validate([
            'doctorId' => ['required', doctor_exists()],
            'form.day_of_week' => 'required|integer|between:0,6',
            'form.start_time' => 'required|date_format:H:i',
            'form.end_time' => 'required|date_format:H:i|after:form.start_time',
            'form.slot_minutes' => 'required|integer|between:5,120',
            'form.max_patients' => 'nullable|integer|min:1',
            'form.room' => 'nullable|string|max:50',
            'copyDays' => 'array',
        ]);

        $days = array_unique(array_merge([(int) $this->form['day_of_week']], array_map('intval', $this->copyDays)));
        foreach ($days as $day) {
            $overlap = DoctorSchedule::where('staff_id', $this->doctorId)->where('day_of_week', $day)
                ->where('start_time', '<', $this->form['end_time'])->where('end_time', '>', $this->form['start_time'])->exists();
            if ($overlap) {
                $this->addError('form.start_time', 'Overlaps an existing session on '.DoctorSchedule::DAYS[$day].'.');

                return;
            }
        }
        foreach ($days as $day) {
            DoctorSchedule::create([
                'staff_id' => $this->doctorId, 'day_of_week' => $day, 'start_time' => $this->form['start_time'], 'end_time' => $this->form['end_time'],
                'slot_minutes' => $this->form['slot_minutes'], 'max_patients' => $this->form['max_patients'] ?: null, 'room' => $this->form['room'] ?: null,
            ]);
        }
        $this->copyDays = [];
        $this->toast('Schedule saved.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('schedules.manage');
        $s = DoctorSchedule::findOrFail($id);
        $s->update(['is_active' => ! $s->is_active]);
    }

    public function remove(int $id): void
    {
        $this->authorize('schedules.manage');
        DoctorSchedule::findOrFail($id)->delete();
    }

    public function addLeave(): void
    {
        $this->authorize('schedules.manage');
        $this->validate(['leaveDate' => 'required|date|after_or_equal:today', 'leaveReason' => 'nullable|string|max:150']);
        DoctorLeave::create(['staff_id' => $this->doctorId, 'date' => $this->leaveDate, 'reason' => $this->leaveReason ?: null]);
        $this->reset('leaveDate', 'leaveReason');
        $this->toast('Leave added – the doctor will not be bookable that day.');
    }

    public function removeLeave(int $id): void
    {
        $this->authorize('schedules.manage');
        DoctorLeave::findOrFail($id)->delete();
    }

    public function with(): array
    {
        $doctor = $this->doctorId ? Staff::with(['schedules' => fn ($q) => $q->orderBy('day_of_week')->orderBy('start_time'), 'leaves' => fn ($q) => $q->whereDate('date', '>=', today())->orderBy('date')])->find($this->doctorId) : null;

        return [
            'doctors' => Staff::doctors()->active()->orderBy('name')->get(),
            'doctor' => $doctor,
            'days' => DoctorSchedule::DAYS,
        ];
    }
}; ?>

<div>
    <x-page-header title="Doctor Schedules" subtitle="Slots & availability" :breadcrumbs="['Appointments' => route('tenant.appointments.index')]" />

    <div class="row g-4">
        <div class="col-xl-3">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Doctors</h6></div>
                <div class="list-group list-group-flush">
                    @foreach ($doctors as $d)
                        <button class="list-group-item list-group-item-action d-flex align-items-center gap-2 {{ (string) $d->id === (string) $doctorId ? 'active' : '' }}" wire:click="$set('doctorId', '{{ $d->id }}')">
                            <x-avatar :src="$d->photoUrl()" :name="$d->name" size="sm" />
                            <div class="text-start min-w-0"><div class="text-truncate">{{ $d->display_name }}</div><small class="opacity-75">{{ $d->specialization }}</small></div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-xl-9">
            @if ($doctor)
                <div class="card">
                    <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">Weekly sessions – {{ $doctor->display_name }}</h5><span class="text-muted fs-13">Fee {{ money($doctor->consultation_fee) }}</span></div>
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            @foreach ($days as $i => $dayName)
                                <div class="col-md-6 col-xl-3">
                                    <div class="border rounded p-2 h-100">
                                        <h6 class="fs-13 mb-2">{{ $dayName }}</h6>
                                        @forelse ($doctor->schedules->where('day_of_week', $i) as $s)
                                            <div class="d-flex justify-content-between align-items-center fs-12 mb-1 {{ $s->is_active ? '' : 'text-decoration-line-through text-muted' }}">
                                                <span>{{ fmt_time($s->start_time) }}–{{ fmt_time($s->end_time) }} <span class="text-muted">· {{ $s->slot_minutes }}m{{ $s->room ? ' · '.$s->room : '' }}</span></span>
                                                <span>
                                                    <button class="btn btn-link p-0 fs-12" wire:click="toggle({{ $s->id }})" title="Enable / disable"><i class="ri-toggle-line"></i></button>
                                                    <button class="btn btn-link p-0 fs-12 text-danger" x-on:click="$confirm('Remove this session?', () => $wire.remove({{ $s->id }}))"><i class="ri-delete-bin-line"></i></button>
                                                </span>
                                            </div>
                                        @empty
                                            <small class="text-muted">Off</small>
                                        @endforelse
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <h6>Add session</h6>
                        <div class="row g-2 align-items-end">
                            <x-form.select class="col-md-2 mb-0" label="Day" model="form.day_of_week" :options="$days" :placeholder="false" />
                            <x-form.input class="col-md-2 mb-0" label="Start" model="form.start_time" type="time" />
                            <x-form.input class="col-md-2 mb-0" label="End" model="form.end_time" type="time" />
                            <x-form.input class="col-md-2 mb-0" label="Slot (min)" model="form.slot_minutes" type="number" />
                            <x-form.input class="col-md-2 mb-0" label="Max patients" model="form.max_patients" type="number" />
                            <x-form.input class="col-md-2 mb-0" label="Room" model="form.room" />
                            <div class="col-12">
                                <span class="fs-13 text-muted me-2">Also repeat on:</span>
                                @foreach ($days as $i => $dayName)
                                    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="cd{{ $i }}" value="{{ $i }}" wire:model="copyDays"><label class="form-check-label fs-13" for="cd{{ $i }}">{{ substr($dayName, 0, 3) }}</label></div>
                                @endforeach
                            </div>
                            <div class="col-12"><button class="btn btn-primary btn-sm" wire:click="add"><i class="ri-add-line me-1"></i>Add session</button></div>
                        </div>
                    </div>
                </div>
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">Leaves / unavailable days</h5></div>
                    <div class="card-body">
                        <div class="row g-2 align-items-end mb-3">
                            <x-form.input class="col-md-4 mb-0" label="Date" model="leaveDate" type="date" />
                            <x-form.input class="col-md-6 mb-0" label="Reason" model="leaveReason" />
                            <div class="col-md-2"><button class="btn btn-light-primary w-100" wire:click="addLeave">Add</button></div>
                        </div>
                        @forelse ($doctor->leaves as $l)
                            <span class="badge bg-warning-subtle text-warning me-1 mb-1 p-2">{{ fmt_date($l->date) }} {{ $l->reason ? '· '.$l->reason : '' }}
                                <i class="ri-close-line ms-1" role="button" wire:click="removeLeave({{ $l->id }})"></i></span>
                        @empty
                            <span class="text-muted fs-13">No upcoming leaves.</span>
                        @endforelse
                    </div>
                </div>
            @else
                <div class="alert alert-info">Add doctors in HR → Staff Directory (type: Doctor) to manage schedules.</div>
            @endif
        </div>
    </div>
</div>
