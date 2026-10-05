<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\Toasts;
use App\Models\OtRoom;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\Surgery;
use App\Support\Sequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('OT Schedule')] class extends Component
{
    use SearchesPatients, Toasts;

    #[Url]
    public string $date = '';

    public bool $showForm = false;

    public array $form = [];

    public array $team = [];

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('ot.manage');
        $start = Carbon::parse($this->date.' 09:00');
        $this->form = ['patient_id' => null, 'ot_room_id' => (string) OtRoom::value('id'), 'procedure_name' => '', 'surgery_type' => 'major',
            'scheduled_start' => $start->format('Y-m-d\TH:i'), 'scheduled_end' => $start->copy()->addHours(2)->format('Y-m-d\TH:i'),
            'surgeon_id' => null, 'anesthesia_type' => 'general', 'charges' => '', 'pre_op_notes' => ''];
        $this->team = [];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function addTeam(): void
    {
        $this->team[] = ['staff_id' => '', 'role' => 'assistant_surgeon'];
    }

    public function removeTeam(int $i): void
    {
        unset($this->team[$i]);
        $this->team = array_values($this->team);
    }

    public function save()
    {
        $this->authorize('ot.manage');
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.ot_room_id' => ['required', tenant_exists('ot_rooms')],
            'form.procedure_name' => 'required|string|max:200',
            'form.surgery_type' => 'required|in:minor,major,emergency',
            'form.scheduled_start' => 'required|date',
            'form.scheduled_end' => 'required|date|after:form.scheduled_start',
            'form.surgeon_id' => ['required', doctor_exists()],
            'form.anesthesia_type' => 'nullable|string|max:30',
            'form.charges' => 'nullable|integer|min:0',
            'form.pre_op_notes' => 'nullable|string|max:2000',
            'team.*.staff_id' => ['required', tenant_exists('staff')],
            'team.*.role' => 'required|in:'.implode(',', array_keys(Surgery::TEAM_ROLES)),
        ], [], ['form.patient_id' => 'patient', 'form.surgeon_id' => 'surgeon', 'form.ot_room_id' => 'OT room']);

        $clash = Surgery::where('ot_room_id', $this->form['ot_room_id'])->whereNotIn('status', ['cancelled', 'completed'])
            ->where('scheduled_start', '<', $this->form['scheduled_end'])->where('scheduled_end', '>', $this->form['scheduled_start'])->first();
        if ($clash) {
            $this->addError('form.scheduled_start', "Room is booked {$clash->scheduled_start->format('H:i')}–{$clash->scheduled_end->format('H:i')} ({$clash->surgery_no}).");

            return;
        }

        $surgery = DB::transaction(function () {
            $patient = Patient::with('currentAdmission')->findOrFail($this->form['patient_id']);
            $surgery = Surgery::create(array_map(fn ($v) => $v === '' ? null : $v, $this->form) + [
                'surgery_no' => Sequence::code('surgery', 'OT'),
                'ipd_admission_id' => $patient->currentAdmission?->id,
                'status' => 'scheduled',
                'charges' => $this->form['charges'] ?: 0,
                'created_by' => auth()->id(),
            ]);
            foreach ($this->team as $m) {
                $surgery->team()->create($m);
            }

            return $surgery;
        });

        session()->flash('success', "Surgery {$surgery->surgery_no} scheduled.");

        return $this->redirect(route('tenant.ot.show', $surgery), navigate: true);
    }

    public function with(): array
    {
        $rooms = OtRoom::with(['surgeries' => fn ($q) => $q->whereDate('scheduled_start', $this->date)->with(['patient', 'surgeon'])->orderBy('scheduled_start')])->orderBy('name')->get();

        return [
            'rooms' => $rooms,
            'upcoming' => Surgery::with(['patient', 'room', 'surgeon'])->whereDate('scheduled_start', '>', $this->date)->whereNotIn('status', ['cancelled', 'completed'])->orderBy('scheduled_start')->limit(10)->get(),
            'doctors' => $this->doctorOptions(),
            'staffOptions' => Staff::active()->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->display_name.' ('.(Staff::TYPES[$s->staff_type] ?? $s->staff_type).')'])->all(),
            'roomOptions' => OtRoom::orderBy('name')->pluck('name', 'id'),
            'teamRoles' => Surgery::TEAM_ROLES,
        ];
    }
}; ?>

<div wire:poll.30s>
    <x-page-header title="Operation Theater" :subtitle="fmt_date($date, 'l, d M Y')">
        <input type="date" class="form-control form-control-sm w-auto" wire:model.live="date">
        @can('ot.manage')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>Schedule surgery</button>@endcan
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-8">
            @forelse ($rooms as $room)
                <div class="card">
                    <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0"><i class="ri-surgical-mask-line me-1"></i>{{ $room->name }}</h6><x-status :value="$room->status" /></div>
                    <ul class="list-group list-group-flush">
                        @forelse ($room->surgeries as $s)
                            <li class="list-group-item d-flex align-items-center gap-3">
                                <div class="text-center" style="width: 90px;"><strong>{{ $s->scheduled_start->format('H:i') }}</strong><div class="fs-12 text-muted">→ {{ $s->scheduled_end->format('H:i') }}</div></div>
                                <div class="flex-grow-1">
                                    <a href="{{ route('tenant.ot.show', $s) }}" wire:navigate class="fw-semibold">{{ $s->procedure_name }}</a>
                                    <div class="fs-13">{{ $s->patient->full_name }} · {{ $s->surgeon->display_name }} · <span class="text-muted">{{ label($s->surgery_type) }}</span></div>
                                </div>
                                <x-status :value="$s->status" />
                            </li>
                        @empty
                            <li class="list-group-item text-muted">No surgeries scheduled.</li>
                        @endforelse
                    </ul>
                </div>
            @empty
                <div class="alert alert-info">No OT rooms configured. @can('ot.manage')<a href="{{ route('tenant.ot.rooms') }}" wire:navigate>Add rooms</a>@endcan</div>
            @endforelse
        </div>
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Upcoming</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($upcoming as $s)
                        <li class="list-group-item"><a href="{{ route('tenant.ot.show', $s) }}" wire:navigate class="fw-semibold">{{ $s->procedure_name }}</a><div class="fs-12 text-muted">{{ fmt_datetime($s->scheduled_start) }} · {{ $s->room->name }} · {{ $s->patient->full_name }}</div></li>
                    @empty
                        <li class="list-group-item text-muted">Nothing upcoming.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <x-modal wire:model="showForm" title="Schedule surgery" size="xl">
        <div class="row">
            <x-form.search-select class="col-md-6" label="Patient" model="form.patient_id" search="searchPatients" required />
            <x-form.input class="col-md-6" label="Procedure" model="form.procedure_name" required />
            <x-form.select class="col-md-3" label="OT room" model="form.ot_room_id" :options="$roomOptions" required />
            <x-form.select class="col-md-3" label="Type" model="form.surgery_type" :options="['minor' => 'Minor', 'major' => 'Major', 'emergency' => 'Emergency']" :placeholder="false" />
            <x-form.input class="col-md-3" label="Start" model="form.scheduled_start" type="datetime-local" required />
            <x-form.input class="col-md-3" label="End" model="form.scheduled_end" type="datetime-local" required />
            <x-form.search-select class="col-md-4" label="Primary surgeon" model="form.surgeon_id" :options="$doctors" required />
            <x-form.select class="col-md-4" label="Anesthesia" model="form.anesthesia_type" :options="['general' => 'General', 'spinal' => 'Spinal', 'epidural' => 'Epidural', 'regional' => 'Regional block', 'local' => 'Local', 'sedation' => 'Sedation']" />
            <x-form.money class="col-md-4" label="OT charges" model="form.charges" />
            <x-form.textarea class="col-12" label="Pre-op notes" model="form.pre_op_notes" rows="2" />
        </div>
        <h6>Surgical team</h6>
        @foreach ($team as $i => $m)
            <div class="row g-2 mb-2" wire:key="tm-{{ $i }}">
                <div class="col-md-6"><x-form.search-select class="mb-0" model="team.{{ $i }}.staff_id" :options="$staffOptions" /></div>
                <div class="col-md-5"><select class="form-select" wire:model="team.{{ $i }}.role">@foreach ($teamRoles as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-1"><button class="btn btn-light-danger w-100" wire:click="removeTeam({{ $i }})"><i class="ri-close-line"></i></button></div>
            </div>
        @endforeach
        <button class="btn btn-sm btn-light-primary" wire:click="addTeam"><i class="ri-add-line"></i> Team member</button>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Schedule</button></x-slot:footer>
    </x-modal>
</div>
