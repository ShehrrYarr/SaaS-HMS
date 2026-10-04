<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\AppointmentService;
use App\Services\OpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Appointments')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'start_time';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['start_time', 'token_no', 'created_at'];

    #[Url]
    public string $date = '';

    #[Url]
    public string $doctor = '';

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    public array $form = [];

    public ?string $patientLabel = null;

    public bool $showCancel = false;

    public ?int $cancelId = null;

    public string $cancelReason = '';

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
        $staff = auth()->user()->staff;
        if (! $this->doctor && $staff?->staff_type === 'doctor') {
            $this->doctor = (string) $staff->id;
        }
        if (request()->boolean('book')) {
            $this->book(request()->integer('patient') ?: null);
        }
    }

    public function book(?int $patientId = null): void
    {
        $this->authorize('appointments.manage');
        $this->form = [
            'id' => null, 'patient_id' => $patientId ? (string) $patientId : null, 'doctor_id' => $this->doctor ?: null,
            'appointment_date' => $this->date ?: today()->toDateString(), 'start_time' => null, 'mode' => 'in_person',
            'source' => 'walk_in', 'reason' => '', 'fee' => '',
        ];
        $this->patientLabel = $this->patientLabel($patientId);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('appointments.manage');
        $a = Appointment::findOrFail($id);
        $this->form = [
            'id' => $a->id, 'patient_id' => (string) $a->patient_id, 'doctor_id' => (string) $a->doctor_id,
            'appointment_date' => $a->appointment_date->toDateString(), 'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
            'mode' => $a->mode, 'source' => $a->source, 'reason' => (string) $a->reason, 'fee' => (string) $a->fee,
        ];
        $this->patientLabel = $this->patientLabel($a->patient_id);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedFormDoctorId($value): void
    {
        $this->form['start_time'] = null;
        $this->form['fee'] = (string) (Staff::find($value)?->consultation_fee ?? '');
    }

    public function updatedFormAppointmentDate(): void
    {
        $this->form['start_time'] = null;
    }

    public function save(AppointmentService $service): void
    {
        $this->authorize('appointments.manage');
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.doctor_id' => ['required', doctor_exists()],
            'form.appointment_date' => 'required|date|after_or_equal:today',
            'form.start_time' => 'required|date_format:H:i',
            'form.mode' => 'required|in:in_person,video',
            'form.source' => 'required|in:walk_in,phone,online,portal',
            'form.reason' => 'nullable|string|max:255',
            'form.fee' => 'nullable|numeric|min:0',
        ], [], ['form.patient_id' => 'patient', 'form.doctor_id' => 'doctor', 'form.start_time' => 'time slot']);

        $appointment = $service->book(Patient::findOrFail($this->form['patient_id']), Staff::doctors()->findOrFail($this->form['doctor_id']), $this->form);
        $this->showForm = false;
        $this->date = $appointment->appointment_date->toDateString();
        $this->toast("Appointment {$appointment->appointment_no} saved for ".fmt_time($appointment->start_time).'.');
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorize('appointments.manage');
        abort_unless(in_array($status, ['confirmed', 'no_show']), 400);
        Appointment::findOrFail($id)->update(['status' => $status]);
        $this->toast('Appointment '.label($status).'.');
    }

    public function checkIn(int $id, OpdService $opd): void
    {
        abort_unless(auth()->user()->canAny(['appointments.manage', 'queue.manage']), 403);
        $visit = $opd->checkIn(Appointment::findOrFail($id));
        $this->toast("Checked in · Token #{$visit->token_no}");
    }

    public function askCancel(int $id): void
    {
        $this->cancelId = $id;
        $this->cancelReason = '';
        $this->showCancel = true;
    }

    public function cancel(): void
    {
        $this->authorize('appointments.manage');
        $this->validate(['cancelReason' => 'required|string|max:200']);
        Appointment::findOrFail($this->cancelId)->update(['status' => 'cancelled', 'cancel_reason' => $this->cancelReason]);
        $this->showCancel = false;
        $this->toast('Appointment cancelled.', 'warning');
    }

    public function with(AppointmentService $service): array
    {
        $query = Appointment::with(['patient', 'doctor', 'opdVisit'])
            ->when($this->date, fn ($q) => $q->whereDate('appointment_date', $this->date))
            ->when($this->doctor, fn ($q) => $q->where('doctor_id', $this->doctor))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('appointment_no', 'like', "%{$this->search}%")
                ->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $slots = [];
        if ($this->showForm && ! empty($this->form['doctor_id']) && ! empty($this->form['appointment_date'])) {
            $doctor = Staff::find($this->form['doctor_id']);
            $slots = $doctor ? $service->availableSlots($doctor, $this->form['appointment_date'], $this->form['id'] ?? null) : [];
            if (! empty($this->form['start_time']) && ! isset($slots[$this->form['start_time']])) {
                $slots = [$this->form['start_time'] => fmt_time($this->form['start_time']).' (current)'] + $slots;
            }
        }

        $counts = Appointment::whereDate('appointment_date', $this->date ?: today())->when($this->doctor, fn ($q) => $q->where('doctor_id', $this->doctor))
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'appointments' => $this->applySort($query)->paginate($this->perPage),
            'doctors' => $this->doctorOptions(),
            'slots' => $slots,
            'counts' => $counts,
        ];
    }
}; ?>

<div>
    <x-page-header title="Appointments" subtitle="Scheduling">
        @can('queue.manage')<a href="{{ route('tenant.queue.index') }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-list-ordered me-1"></i>OPD Queue</a>@endcan
        @can('appointments.manage')<button class="btn btn-primary btn-sm" wire:click="book"><i class="ri-calendar-event-line me-1"></i>Book Appointment</button>@endcan
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach (['booked', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show'] as $s)
            <button class="btn btn-sm {{ $status === $s ? 'btn-'.status_color($s) : 'btn-light' }}" wire:click="$set('status', '{{ $status === $s ? '' : $s }}')">
                {{ label($s) }} <span class="badge bg-white text-dark ms-1">{{ $counts[$s] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Search patient or appointment #...">
            <input type="date" class="form-control w-auto" wire:model.live="date">
            <select class="form-select w-auto" wire:model.live="doctor">
                <option value="">All doctors</option>
                @foreach ($doctors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <x-th field="start_time" :sort="$sortField" :dir="$sortDirection">Time</x-th>
                        <x-th field="token_no" :sort="$sortField" :dir="$sortDirection">Token</x-th>
                        <th>Patient</th><th>Doctor</th><th>Type</th><th>Reason</th><th>Status</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($appointments as $a)
                        <tr wire:key="a-{{ $a->id }}">
                            <td class="text-nowrap fw-semibold">{{ fmt_time($a->start_time) }}<div class="fs-12 text-muted">{{ fmt_date($a->appointment_date) }}</div></td>
                            <td>{{ $a->token_no ? '#'.$a->token_no : '—' }}</td>
                            <td><a href="{{ route('tenant.patients.show', $a->patient) }}" wire:navigate class="fw-semibold">{{ $a->patient->full_name }}</a><div class="fs-12 text-muted">{{ $a->patient->uhid }} · {{ $a->appointment_no }}</div></td>
                            <td>{{ $a->doctor->display_name }}</td>
                            <td>
                                <span class="badge bg-light text-body">{{ label($a->source) }}</span>
                                @if ($a->isVideo())<span class="badge bg-info-subtle text-info"><i class="ri-video-chat-line"></i> Video</span>@endif
                            </td>
                            <td class="fs-13">{{ $a->reason ?: '—' }}</td>
                            <td><x-status :value="$a->status" /></td>
                            <td class="text-end text-nowrap">
                                @if (in_array($a->status, ['booked', 'confirmed']))
                                    @if ($a->appointment_date->isToday())
                                        @canany(['appointments.manage', 'queue.manage'])
                                            <button class="btn btn-sm btn-success" wire:click="checkIn({{ $a->id }})" wire:loading.attr="disabled"><i class="ri-login-circle-line"></i> Check in</button>
                                        @endcanany
                                    @endif
                                    @can('appointments.manage')
                                        @if ($a->status === 'booked')<button class="btn btn-sm btn-light-info icon-btn-sm" title="Confirm" wire:click="setStatus({{ $a->id }}, 'confirmed')"><i class="ri-check-line"></i></button>@endif
                                        <button class="btn btn-sm btn-light-primary icon-btn-sm" title="Reschedule" wire:click="edit({{ $a->id }})"><i class="ri-calendar-2-line"></i></button>
                                        <button class="btn btn-sm btn-light-warning icon-btn-sm" title="No show" x-on:click="$confirm('Mark as no-show?', () => $wire.setStatus({{ $a->id }}, 'no_show'), { color: 'warning' })"><i class="ri-user-unfollow-line"></i></button>
                                        <button class="btn btn-sm btn-light-danger icon-btn-sm" title="Cancel" wire:click="askCancel({{ $a->id }})"><i class="ri-close-line"></i></button>
                                    @endcan
                                @endif
                                @if ($a->isVideo() && in_array($a->status, ['booked', 'confirmed', 'checked_in', 'in_consultation']) && hospital()->hasModule('telemedicine'))
                                    @can('telemedicine.conduct')<a href="{{ route('tenant.telemedicine.room', $a) }}" wire:navigate class="btn btn-sm btn-info"><i class="ri-video-chat-line"></i> Join</a>@endcan
                                @endif
                                @if ($a->opdVisit && hospital()->hasModule('opd'))
                                    @can('opd.consult')<a href="{{ route('tenant.opd.consult', $a->opdVisit) }}" wire:navigate class="btn btn-sm btn-light-primary">Consult</a>@endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No appointments for the selected filters." icon="ri-calendar-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $appointments->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="($form['id'] ?? null) ? 'Reschedule appointment' : 'Book appointment'" size="lg">
        <div class="row">
            <x-form.search-select class="col-12" label="Patient" model="form.patient_id" search="searchPatients" :selected-label="$patientLabel" placeholder="Search by name, UHID or phone" required />
            <x-form.search-select class="col-md-6" label="Doctor" model="form.doctor_id" :options="$doctors" live required />
            <x-form.input class="col-md-3" label="Date" model="form.appointment_date" type="date" live required />
            <x-form.input class="col-md-3" label="Fee" model="form.fee" type="number" step="0.01" />
            <div class="col-12 mb-3">
                <label class="form-label">Available slots <span class="text-danger">*</span></label>
                @if (empty($form['doctor_id']))
                    <p class="text-muted fs-13 mb-0">Choose a doctor to see free slots.</p>
                @elseif (empty($slots))
                    <div class="alert alert-warning py-2 mb-0">No free slots on this date (not scheduled, on leave, or fully booked).</div>
                @else
                    <div class="d-flex flex-wrap gap-2" style="max-height: 180px; overflow-y: auto;">
                        @foreach ($slots as $value => $text)
                            <input type="radio" class="btn-check" id="slot-{{ str_replace(':', '', $value) }}" value="{{ $value }}" wire:model="form.start_time">
                            <label class="btn btn-sm btn-outline-primary" for="slot-{{ str_replace(':', '', $value) }}">{{ $text }}</label>
                        @endforeach
                    </div>
                @endif
                @error('form.start_time')<div class="text-danger fs-12 mt-1">{{ $message }}</div>@enderror
            </div>
            <x-form.select class="col-md-4" label="Consultation" model="form.mode" :options="['in_person' => 'In person', 'video' => 'Video (telemedicine)']" :placeholder="false" />
            <x-form.select class="col-md-4" label="Source" model="form.source" :options="['walk_in' => 'Walk-in', 'phone' => 'Phone', 'online' => 'Online', 'portal' => 'Patient portal']" :placeholder="false" />
            <x-form.input class="col-md-4" label="Reason" model="form.reason" />
        </div>
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Close</button>
            <button class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save appointment</button>
        </x-slot:footer>
    </x-modal>

    <x-modal wire:model="showCancel" title="Cancel appointment">
        <x-form.textarea label="Reason" model="cancelReason" rows="2" required />
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Back</button>
            <button class="btn btn-danger" wire:click="cancel">Cancel appointment</button>
        </x-slot:footer>
    </x-modal>
</div>
