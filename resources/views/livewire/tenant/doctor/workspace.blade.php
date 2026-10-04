<?php

use App\Models\Appointment;
use App\Models\OpdVisit;
use App\Models\Staff;
use App\Services\OpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Doctor Workspace')] class extends Component
{
    #[Url]
    public ?string $doctorId = null;

    public function mount(): void
    {
        $staff = auth()->user()->staff;
        if ($staff?->staff_type === 'doctor') {
            $this->doctorId = (string) $staff->id;
        }
        $this->doctorId ??= (string) Staff::doctors()->active()->orderBy('name')->value('id');
    }

    public function start(int $visitId, OpdService $opd)
    {
        $this->authorize('opd.consult');
        $visit = OpdVisit::findOrFail($visitId);
        $opd->startConsultation($visit);

        return $this->redirect(route('tenant.opd.consult', $visit), navigate: true);
    }

    public function with(): array
    {
        $isDoctor = auth()->user()->staff?->staff_type === 'doctor';
        $doctorId = $isDoctor ? auth()->user()->staff->id : $this->doctorId;
        $today = OpdVisit::with(['patient.allergies', 'appointment'])->where('doctor_id', $doctorId)->whereDate('visit_date', today())->orderBy('token_no')->get();

        return [
            'isDoctor' => $isDoctor,
            'doctors' => Staff::doctors()->active()->orderBy('name')->get(),
            'doctor' => Staff::find($doctorId),
            'inConsultation' => $today->where('status', 'in_consultation'),
            'waiting' => $today->where('status', 'waiting'),
            'completed' => $today->where('status', 'completed'),
            'upcoming' => Appointment::with('patient')->where('doctor_id', $doctorId)->whereDate('appointment_date', '>', today())
                ->whereIn('status', ['booked', 'confirmed'])->orderBy('appointment_date')->orderBy('start_time')->limit(8)->get(),
            'followUps' => OpdVisit::with('patient')->where('doctor_id', $doctorId)->whereNotNull('follow_up_date')
                ->whereBetween('follow_up_date', [today(), today()->addDays(7)])->orderBy('follow_up_date')->limit(8)->get(),
            'video' => Appointment::with('patient')->where('doctor_id', $doctorId)->whereDate('appointment_date', today())->where('mode', 'video')
                ->whereNotIn('status', ['cancelled', 'completed', 'no_show'])->orderBy('start_time')->get(),
        ];
    }
}; ?>

<div wire:poll.15s>
    <x-page-header title="Doctor Workspace" :subtitle="$doctor?->display_name">
        @unless ($isDoctor)
            <select class="form-select form-select-sm w-auto" wire:model.live="doctorId">
                @foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->display_name }}</option>@endforeach
            </select>
        @endunless
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-4"><x-stat-card title="Waiting" :value="$waiting->count()" icon="ri-time-line" color="warning" /></div>
        <div class="col-sm-4"><x-stat-card title="In consultation" :value="$inConsultation->count()" icon="ri-stethoscope-line" color="primary" /></div>
        <div class="col-sm-4"><x-stat-card title="Seen today" :value="$completed->count()" icon="ri-checkbox-circle-line" color="success" /></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Today's patients</h5></div>
                <div class="table-responsive">
                    <table class="table table-hms table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Token</th><th>Patient</th><th>Complaint</th><th>Type</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($inConsultation->concat($waiting)->concat($completed) as $v)
                                <tr wire:key="wv-{{ $v->id }}" class="{{ $v->status === 'in_consultation' ? 'table-primary' : '' }}">
                                    <td class="fs-5 fw-bold">#{{ $v->token_no }}</td>
                                    <td>
                                        <strong>{{ $v->patient->full_name }}</strong>
                                        @if ($v->patient->allergies->isNotEmpty())<i class="ri-alarm-warning-fill text-danger" title="Allergies: {{ $v->patient->allergies->pluck('allergen')->join(', ') }}"></i>@endif
                                        <div class="fs-12 text-muted">{{ $v->patient->uhid }} · {{ $v->patient->age_gender }}</div>
                                    </td>
                                    <td class="fs-13">{{ $v->chief_complaint ?: '—' }}</td>
                                    <td>{{ label($v->visit_type) }}</td>
                                    <td><x-status :value="$v->status" /></td>
                                    <td class="text-end">
                                        @if ($v->status === 'waiting')
                                            <button class="btn btn-sm btn-primary" wire:click="start({{ $v->id }})"><i class="ri-play-line"></i> Start</button>
                                        @else
                                            <a href="{{ route('tenant.opd.consult', $v) }}" wire:navigate class="btn btn-sm {{ $v->status === 'completed' ? 'btn-light' : 'btn-success' }}">{{ $v->status === 'completed' ? 'View' : 'Continue' }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="6" message="No OPD patients yet today." icon="ri-stethoscope-line" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            @if ($video->isNotEmpty() && hospital()->hasModule('telemedicine'))
                <div class="card border-info">
                    <div class="card-header"><h6 class="card-title mb-0 text-info"><i class="ri-video-chat-line me-1"></i>Video consultations today</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($video as $a)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>{{ fmt_time($a->start_time) }} · {{ $a->patient->full_name }}</span>
                                <a href="{{ route('tenant.telemedicine.room', $a) }}" wire:navigate class="btn btn-sm btn-info">Join</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Upcoming appointments</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($upcoming as $a)
                        <li class="list-group-item"><strong>{{ fmt_date($a->appointment_date, 'D d M') }}</strong> {{ fmt_time($a->start_time) }} · {{ $a->patient->full_name }}</li>
                    @empty
                        <li class="list-group-item text-muted">None scheduled.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Follow-ups due (7 days)</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($followUps as $v)
                        <li class="list-group-item"><strong>{{ fmt_date($v->follow_up_date, 'D d M') }}</strong> · <a href="{{ route('tenant.patients.show', $v->patient) }}" wire:navigate>{{ $v->patient->full_name }}</a></li>
                    @empty
                        <li class="list-group-item text-muted">No follow-ups due.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
