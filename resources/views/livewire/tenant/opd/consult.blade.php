<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Appointment;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\OpdVisit;
use App\Models\Prescription;
use App\Models\RadiologyTest;
use App\Services\AppointmentService;
use App\Services\DiagnosticsService;
use App\Services\OpdService;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Consultation')] class extends Component
{
    use Toasts;

    public OpdVisit $visit;

    public string $tab = 'notes';

    // e-Prescription
    public array $items = [];

    public string $advice = '';

    public ?string $followUp = null;

    // Orders
    public array $labTests = [];

    public string $labPriority = 'routine';

    public array $imaging = [];

    public string $clinicalHistory = '';

    public const FREQUENCIES = ['1-0-0' => '1-0-0 (morning)', '0-0-1' => '0-0-1 (night)', '1-0-1' => '1-0-1 (twice)', '1-1-1' => '1-1-1 (thrice)', '1-1-1-1' => '1-1-1-1 (4×)', 'SOS' => 'SOS (as needed)', 'STAT' => 'STAT (once)', 'Weekly' => 'Once a week'];

    public function mount(OpdVisit $visit): void
    {
        $this->visit = $visit;
        $this->followUp = $visit->follow_up_date?->toDateString();
        $this->addItem();
    }

    public function addItem(): void
    {
        $this->items[] = ['medicine_id' => null, 'medicine_name' => '', 'dosage' => '', 'frequency' => '1-0-1', 'duration' => '5', 'route' => 'Oral', 'quantity' => 10, 'instructions' => 'After meals'];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    #[Renderless]
    public function searchMedicines(string $q = ''): array
    {
        return Medicine::withStock()->search($q)->where('is_active', true)->orderBy('name')->limit(20)->get()
            ->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->label.' · '.(int) $m->stock.' in stock'])->all();
    }

    public function updatedItems($value, $key): void
    {
        [$i, $field] = explode('.', $key) + [null, null];
        if ($field === 'medicine_id' && $value) {
            $m = Medicine::find($value);
            $this->items[$i]['medicine_name'] = (string) $m?->label;
            $this->items[$i]['dosage'] = $this->items[$i]['dosage'] ?: (string) $m?->strength;
        }
        if (in_array($field, ['frequency', 'duration', 'medicine_id'])) {
            $perDay = array_sum(array_map('intval', explode('-', (string) $this->items[$i]['frequency'])));
            $days = (int) $this->items[$i]['duration'];
            if ($perDay > 0 && $days > 0) {
                $this->items[$i]['quantity'] = $perDay * $days;
            }
        }
    }

    public function savePrescription(): void
    {
        $this->authorize('prescriptions.create');
        $this->validate([
            'items' => 'required|array|min:1',
            'items.*.medicine_name' => 'required|string|max:150',
            'items.*.dosage' => 'nullable|string|max:50',
            'items.*.frequency' => 'required|string|max:50',
            'items.*.duration' => 'nullable|string|max:50',
            'items.*.route' => 'nullable|string|max:30',
            'items.*.quantity' => 'required|integer|min:1|max:1000',
            'items.*.instructions' => 'nullable|string|max:150',
            'advice' => 'nullable|string|max:2000',
            'followUp' => 'nullable|date|after:today',
        ], [], ['items.*.medicine_name' => 'medicine']);

        $rx = DB::transaction(function () {
            $rx = Prescription::create([
                'prescription_no' => Sequence::code('prescription', 'RX'),
                'patient_id' => $this->visit->patient_id,
                'doctor_id' => $this->visit->doctor_id,
                'visitable_type' => $this->visit->getMorphClass(),
                'visitable_id' => $this->visit->id,
                'advice' => $this->advice ?: null,
                'follow_up_date' => $this->followUp ?: null,
                'status' => 'issued',
            ]);
            foreach ($this->items as $item) {
                $rx->items()->create([
                    'medicine_id' => $item['medicine_id'] ?: null,
                    'medicine_name' => $item['medicine_name'],
                    'dosage' => $item['dosage'] ?: null,
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'] ? $item['duration'].' days' : null,
                    'route' => $item['route'] ?: null,
                    'quantity' => (int) $item['quantity'],
                    'instructions' => $item['instructions'] ?: null,
                ]);
            }
            if ($this->followUp) {
                $this->visit->update(['follow_up_date' => $this->followUp]);
            }

            return $rx;
        });

        $this->items = [];
        $this->addItem();
        $this->advice = '';
        $this->toast("Prescription {$rx->prescription_no} sent to pharmacy.");
    }

    public function orderLab(DiagnosticsService $diagnostics): void
    {
        $this->authorize('lab.order');
        $this->validate(['labTests' => 'required|array|min:1', 'labPriority' => 'required|in:routine,urgent,stat']);
        $order = $diagnostics->orderLab($this->visit->patient, $this->labTests, $this->visit->doctor, $this->visit, $this->labPriority, $this->clinicalHistory ?: null);
        $this->labTests = [];
        $this->toast("Lab order {$order->order_no} sent to laboratory.");
    }

    public function orderImaging(DiagnosticsService $diagnostics): void
    {
        $this->authorize('radiology.order');
        $this->validate(['imaging' => 'required|array|min:1']);
        $orders = $diagnostics->orderRadiology($this->visit->patient, $this->imaging, $this->visit->doctor, $this->visit, $this->labPriority, $this->clinicalHistory ?: null);
        $this->imaging = [];
        $this->toast(count($orders).' imaging order(s) sent to radiology.');
    }

    public function complete(OpdService $opd, AppointmentService $appointments)
    {
        $this->authorize('opd.consult');
        $this->validate(['followUp' => 'nullable|date|after:today']);
        $opd->completeConsultation($this->visit, $this->followUp);

        if ($this->followUp && hospital()->hasModule('appointments')) {
            $slots = $appointments->availableSlots($this->visit->doctor, $this->followUp);
            if ($slots) {
                $appointments->book($this->visit->patient, $this->visit->doctor, [
                    'appointment_date' => $this->followUp, 'start_time' => array_key_first($slots), 'source' => 'phone',
                    'reason' => 'Follow-up of '.$this->visit->visit_no, 'fee' => $this->visit->doctor->follow_up_fee,
                ]);
            }
        }

        session()->flash('success', 'Consultation completed'.($this->followUp ? ' · follow-up booked for '.fmt_date($this->followUp) : '').'.');

        return $this->redirect(route('tenant.doctor.workspace'), navigate: true);
    }

    public function with(): array
    {
        $patient = $this->visit->patient->load(['allergies', 'latestVital', 'histories']);

        return [
            'patient' => $patient,
            'prescriptions' => $this->visit->prescriptions()->with('items')->latest()->get(),
            'labOrders' => hospital()->hasModule('laboratory') ? $this->visit->labOrders()->with('items.test')->latest()->get() : collect(),
            'radiologyOrders' => hospital()->hasModule('radiology') ? $this->visit->radiologyOrders()->with('test')->latest()->get() : collect(),
            'labCatalog' => hospital()->hasModule('laboratory') ? LabTest::where('is_active', true)->orderBy('name')->get() : collect(),
            'imagingCatalog' => hospital()->hasModule('radiology') ? RadiologyTest::where('is_active', true)->orderBy('name')->get() : collect(),
            'previous' => $patient->opdVisits()->with('doctor', 'diagnoses')->whereKeyNot($this->visit->id)->latest('visit_date')->limit(5)->get(),
            'frequencies' => self::FREQUENCIES,
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Consultation · Token #'.$visit->token_no" :subtitle="$visit->visit_no" :breadcrumbs="['Workspace' => route('tenant.doctor.workspace')]">
        <x-status :value="$visit->status" />
        @if ($visit->status !== 'completed')
            <button class="btn btn-success btn-sm" x-on:click="$confirm('Complete this consultation?', () => $wire.complete(), { color: 'success', confirmText: 'Complete' })"><i class="ri-check-double-line me-1"></i>Complete</button>
        @endif
        @if (hospital()->hasModule('ipd'))
            @can('ipd.admit')<a href="{{ route('tenant.ipd.admit', ['patient' => $patient->id, 'doctor' => $visit->doctor_id]) }}" wire:navigate class="btn btn-light-warning btn-sm"><i class="ri-hotel-bed-line me-1"></i>Admit</a>@endcan
        @endif
    </x-page-header>

    {{-- Patient banner --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <div class="d-flex align-items-center gap-3">
                <x-avatar :src="$patient->photoUrl()" :name="$patient->full_name" size="lg" />
                <div>
                    <h5 class="mb-0"><a href="{{ route('tenant.patients.show', $patient) }}" wire:navigate>{{ $patient->full_name }}</a></h5>
                    <span class="text-muted">{{ $patient->uhid }} · {{ $patient->age_gender }} · {{ $patient->blood_group ?: 'Blood group ?' }}</span>
                </div>
            </div>
            <div><span class="text-muted fs-12 d-block">Chief complaint</span><strong>{{ $visit->chief_complaint ?: '—' }}</strong></div>
            @if ($v = $patient->latestVital)
                <div class="fs-13"><span class="text-muted fs-12 d-block">Vitals ({{ $v->recorded_at->diffForHumans() }})</span>BP {{ $v->bp ?? '—' }} · P {{ $v->pulse ?? '—' }} · T {{ $v->temperature ?? '—' }} · SpO₂ {{ $v->spo2 ?? '—' }}</div>
            @endif
            <div class="ms-auto">
                @forelse ($patient->allergies as $a)
                    <span class="badge bg-danger text-white"><i class="ri-alarm-warning-line"></i> {{ $a->allergen }}</span>
                @empty
                    <span class="badge bg-success-subtle text-success">NKA</span>
                @endforelse
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header pb-0 border-0">
                    <ul class="nav nav-tabs card-header-tabs">
                        @foreach (['notes' => 'Clinical notes', 'vitals' => 'Vitals', 'diagnosis' => 'Diagnosis (ICD)', 'history' => 'History'] as $k => $l)
                            <li class="nav-item"><button class="nav-link {{ $tab === $k ? 'active' : '' }}" wire:click="$set('tab', '{{ $k }}')">{{ $l }}</button></li>
                        @endforeach
                    </ul>
                </div>
                <div class="card-body">
                    @if ($tab === 'notes') <livewire:tenant.emr.notes :patient="$patient" :visitable="$visit" :key="'cn-'.$visit->id" />
                    @elseif ($tab === 'vitals') <livewire:tenant.emr.vitals :patient="$patient" :visitable="$visit" :compact="true" :key="'cv-'.$visit->id" />
                    @elseif ($tab === 'diagnosis') <livewire:tenant.emr.diagnoses :patient="$patient" :visitable="$visit" :key="'cd-'.$visit->id" />
                    @else
                        <h6>Previous visits</h6>
                        @forelse ($previous as $p)
                            <div class="border-bottom py-2 fs-13">
                                <strong>{{ fmt_date($p->visit_date) }}</strong> · {{ $p->doctor->display_name }} · {{ $p->chief_complaint }}
                                @if ($p->diagnoses->isNotEmpty())<div class="text-muted">Dx: {{ $p->diagnoses->pluck('description')->join('; ') }}</div>@endif
                            </div>
                        @empty
                            <p class="text-muted">First visit.</p>
                        @endforelse
                        <h6 class="mt-3">Medical history</h6>
                        @forelse ($patient->histories as $h)<span class="badge bg-light text-body me-1 mb-1">{{ label($h->type) }}: {{ $h->title }}</span>@empty<p class="text-muted">None recorded.</p>@endforelse
                    @endif
                </div>
            </div>

            @if ($labCatalog->isNotEmpty() || $imagingCatalog->isNotEmpty())
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0"><i class="ri-flask-line me-1"></i>Investigations</h5></div>
                    <div class="card-body">
                        <x-form.input label="Clinical history / indication" model="clinicalHistory" placeholder="Shared with lab & radiology" />
                        <div class="row g-3">
                            @if ($labCatalog->isNotEmpty())
                                @can('lab.order')
                                    <div class="col-md-6">
                                        <label class="form-label">Lab tests</label>
                                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                            @foreach ($labCatalog as $t)
                                                <div class="form-check"><input class="form-check-input" type="checkbox" id="lt{{ $t->id }}" value="{{ $t->id }}" wire:model="labTests">
                                                    <label class="form-check-label fs-13" for="lt{{ $t->id }}">{{ $t->name }} <span class="text-muted">· {{ money($t->price) }}</span></label></div>
                                            @endforeach
                                        </div>
                                        @error('labTests')<div class="text-danger fs-12">{{ $message }}</div>@enderror
                                        <div class="d-flex gap-2 mt-2">
                                            <select class="form-select form-select-sm w-auto" wire:model="labPriority"><option value="routine">Routine</option><option value="urgent">Urgent</option><option value="stat">STAT</option></select>
                                            <button class="btn btn-sm btn-info" wire:click="orderLab"><i class="ri-send-plane-line"></i> Send to lab</button>
                                        </div>
                                    </div>
                                @endcan
                            @endif
                            @if ($imagingCatalog->isNotEmpty())
                                @can('radiology.order')
                                    <div class="col-md-6">
                                        <label class="form-label">Imaging</label>
                                        <div class="border rounded p-2" style="max-height: 200px; overflow-y: auto;">
                                            @foreach ($imagingCatalog as $t)
                                                <div class="form-check"><input class="form-check-input" type="checkbox" id="rt{{ $t->id }}" value="{{ $t->id }}" wire:model="imaging">
                                                    <label class="form-check-label fs-13" for="rt{{ $t->id }}">{{ $t->name }}</label></div>
                                            @endforeach
                                        </div>
                                        @error('imaging')<div class="text-danger fs-12">{{ $message }}</div>@enderror
                                        <button class="btn btn-sm btn-info mt-2" wire:click="orderImaging"><i class="ri-send-plane-line"></i> Send to radiology</button>
                                    </div>
                                @endcan
                            @endif
                        </div>
                        @if ($labOrders->isNotEmpty() || $radiologyOrders->isNotEmpty())
                            <hr>
                            @foreach ($labOrders as $o)
                                <div class="fs-13 mb-1"><a href="{{ route('tenant.lab.order', $o) }}" wire:navigate>{{ $o->order_no }}</a> · {{ $o->items->pluck('test.name')->join(', ') }} <x-status :value="$o->status" /></div>
                            @endforeach
                            @foreach ($radiologyOrders as $o)
                                <div class="fs-13 mb-1"><a href="{{ route('tenant.radiology.order', $o) }}" wire:navigate>{{ $o->order_no }}</a> · {{ $o->test->name }} <x-status :value="$o->status" /></div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="ri-capsule-line me-1"></i>e-Prescription</h5>
                    <button class="btn btn-sm btn-light-primary" wire:click="addItem"><i class="ri-add-line"></i> Medicine</button>
                </div>
                <div class="card-body">
                    @can('prescriptions.create')
                        @foreach ($items as $i => $item)
                            <div class="border rounded p-2 mb-2 position-relative" wire:key="rx-{{ $i }}">
                                <button class="btn btn-sm btn-link text-danger position-absolute top-0 end-0" wire:click="removeItem({{ $i }})"><i class="ri-close-line"></i></button>
                                <x-form.search-select class="mb-2 me-4" model="items.{{ $i }}.medicine_id" search="searchMedicines" placeholder="Pharmacy medicine (or type below)" live :selected-label="$item['medicine_name']" />
                                <input type="text" class="form-control form-control-sm mb-2 @error('items.'.$i.'.medicine_name') is-invalid @enderror" placeholder="Medicine name" wire:model="items.{{ $i }}.medicine_name">
                                <div class="row g-1">
                                    <div class="col-4"><input type="text" class="form-control form-control-sm" placeholder="Dose" wire:model="items.{{ $i }}.dosage"></div>
                                    <div class="col-5"><select class="form-select form-select-sm" wire:model.live="items.{{ $i }}.frequency">@foreach ($frequencies as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                    <div class="col-3"><div class="input-group input-group-sm"><input type="number" class="form-control" wire:model.live.debounce.500ms="items.{{ $i }}.duration"><span class="input-group-text">d</span></div></div>
                                    <div class="col-4"><select class="form-select form-select-sm" wire:model="items.{{ $i }}.route">@foreach (['Oral', 'IV', 'IM', 'SC', 'Topical', 'Inhalation', 'Drops', 'Rectal'] as $r)<option>{{ $r }}</option>@endforeach</select></div>
                                    <div class="col-3"><input type="number" class="form-control form-control-sm" title="Quantity" wire:model="items.{{ $i }}.quantity"></div>
                                    <div class="col-5"><input type="text" class="form-control form-control-sm" placeholder="Instructions" wire:model="items.{{ $i }}.instructions"></div>
                                </div>
                            </div>
                        @endforeach
                        <x-form.textarea class="mt-3" label="Advice" model="advice" rows="2" placeholder="Diet, rest, warning signs..." />
                        <x-form.input label="Follow-up date" model="followUp" type="date" hint="On completion a follow-up appointment is booked automatically." />
                        <button class="btn btn-primary w-100" wire:click="savePrescription" wire:loading.attr="disabled"><i class="ri-send-plane-fill me-1"></i>Issue prescription → Pharmacy</button>
                    @endcan

                    @if ($prescriptions->isNotEmpty())
                        <hr>
                        @foreach ($prescriptions as $rx)
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fs-13"><strong>{{ $rx->prescription_no }}</strong> · {{ $rx->items->count() }} item(s) <x-status :value="$rx->status" /></span>
                                <a href="{{ route('tenant.prescriptions.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line"></i></a>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
