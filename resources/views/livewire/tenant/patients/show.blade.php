<?php

use App\Models\Patient;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Patient EMR')] class extends Component
{
    public Patient $patient;

    #[Url]
    public string $tab = 'overview';

    public function mount(Patient $patient): void
    {
        $this->patient = $patient;
    }

    public function with(): array
    {
        $p = $this->patient->load(['tpa', 'allergies', 'latestVital', 'currentAdmission.bed.ward', 'user']);
        $h = hospital();

        $timeline = collect()
            ->merge($h->hasModule('opd') ? $p->opdVisits()->with('doctor')->latest('visit_date')->limit(10)->get()->map(fn ($v) => ['date' => $v->visit_date, 'icon' => 'ri-stethoscope-line', 'color' => 'primary', 'title' => 'OPD visit '.$v->visit_no, 'text' => $v->doctor->display_name.($v->chief_complaint ? ' · '.$v->chief_complaint : ''), 'status' => $v->status]) : [])
            ->merge($h->hasModule('ipd') ? $p->admissions()->with('doctor')->latest('admitted_at')->limit(10)->get()->map(fn ($a) => ['date' => $a->admitted_at, 'icon' => 'ri-hotel-bed-line', 'color' => 'warning', 'title' => 'Admission '.$a->admission_no, 'text' => $a->doctor->display_name.' · '.($a->reason ?? ''), 'status' => $a->status, 'url' => route('tenant.ipd.show', $a)]) : [])
            ->merge($h->hasModule('laboratory') ? $p->labOrders()->withCount('items')->latest('ordered_at')->limit(10)->get()->map(fn ($o) => ['date' => $o->ordered_at, 'icon' => 'ri-flask-line', 'color' => 'info', 'title' => 'Lab order '.$o->order_no, 'text' => $o->items_count.' test(s)', 'status' => $o->status, 'url' => route('tenant.lab.order', $o)]) : [])
            ->merge($p->diagnoses()->latest()->limit(10)->get()->map(fn ($d) => ['date' => $d->created_at, 'icon' => 'ri-file-list-3-line', 'color' => 'danger', 'title' => 'Diagnosis', 'text' => trim(($d->icd_code ? $d->icd_code.' – ' : '').$d->description), 'status' => $d->type]))
            ->sortByDesc('date')->take(15)->values();

        $tabs = ['overview' => 'Overview', 'vitals' => 'Vitals', 'notes' => 'Clinical Notes', 'diagnoses' => 'Diagnoses', 'allergies' => 'Allergies', 'history' => 'Medical History', 'documents' => 'Documents'];
        if ($h->hasModule('opd')) {
            $tabs['visits'] = 'Visits';
            $tabs['prescriptions'] = 'Prescriptions';
        }
        if ($h->hasModule('laboratory')) {
            $tabs['lab'] = 'Lab';
        }
        if ($h->hasModule('radiology')) {
            $tabs['radiology'] = 'Imaging';
        }
        if ($h->hasModule('billing') && auth()->user()->can('billing.view')) {
            $tabs['billing'] = 'Billing';
        }

        return [
            'p' => $p,
            'tabs' => $tabs,
            'timeline' => $timeline,
            'visits' => $this->tab === 'visits' ? $p->opdVisits()->with('doctor')->latest('visit_date')->get()->concat([]) : collect(),
            'admissions' => $this->tab === 'visits' ? $p->admissions()->with(['doctor', 'bed.ward'])->latest('admitted_at')->get() : collect(),
            'prescriptions' => $this->tab === 'prescriptions' ? $p->prescriptions()->with(['doctor', 'items'])->latest()->get() : collect(),
            'labOrders' => $this->tab === 'lab' ? $p->labOrders()->with(['items.test', 'doctor'])->latest('ordered_at')->get() : collect(),
            'radiologyOrders' => $this->tab === 'radiology' ? $p->radiologyOrders()->with(['test', 'doctor'])->latest()->get() : collect(),
            'invoices' => $this->tab === 'billing' ? $p->invoices()->latest()->get() : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$p->full_name" :subtitle="$p->uhid" :breadcrumbs="['Patients' => route('tenant.patients.index')]">
        @can('patients.update')<a href="{{ route('tenant.patients.edit', $p) }}" wire:navigate class="btn btn-light-primary btn-sm"><i class="ri-edit-line me-1"></i>Edit</a>@endcan
        <a href="{{ route('tenant.patients.card', $p->id) }}" target="_blank" class="btn btn-light btn-sm"><i class="ri-bank-card-line me-1"></i>ID Card</a>
        @if (hospital()->hasModule('appointments'))
            @can('appointments.manage')<a href="{{ route('tenant.appointments.index', ['book' => 1, 'patient' => $p->id]) }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-calendar-event-line me-1"></i>Book</a>@endcan
        @endif
        @if (hospital()->hasModule('ipd') && ! $p->currentAdmission)
            @can('ipd.admit')<a href="{{ route('tenant.ipd.admit', ['patient' => $p->id]) }}" wire:navigate class="btn btn-light-warning btn-sm"><i class="ri-hotel-bed-line me-1"></i>Admit</a>@endcan
        @endif
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-3">
            <div class="card">
                <div class="card-body text-center">
                    <x-avatar :src="$p->photoUrl()" :name="$p->full_name" size="xl" class="mb-3" />
                    <h5 class="mb-1">{{ $p->full_name }}</h5>
                    <p class="text-muted mb-2">{{ $p->uhid }} &middot; {{ $p->age_gender }}</p>
                    <div class="d-flex flex-wrap justify-content-center gap-1">
                        @if ($p->blood_group)<span class="badge bg-danger-subtle text-danger"><i class="ri-drop-line"></i> {{ $p->blood_group }}</span>@endif
                        <span class="badge bg-info-subtle text-info">{{ $p->tpa?->name ?? 'Self-pay' }}</span>
                        @if ($p->user_id)<span class="badge bg-success-subtle text-success">Portal</span>@endif
                    </div>
                    @if ($p->currentAdmission)
                        <a href="{{ route('tenant.ipd.show', $p->currentAdmission) }}" wire:navigate class="alert alert-warning d-block mt-3 mb-0 py-2 fs-13">
                            <i class="ri-hotel-bed-line"></i> Admitted &middot; {{ $p->currentAdmission->bed?->label }}
                        </a>
                    @endif
                </div>
                <ul class="list-group list-group-flush fs-13">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Phone</span><span>{{ $p->phone ?: '—' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">DOB</span><span>{{ fmt_date($p->date_of_birth) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">National ID</span><span>{{ $p->national_id ?: '—' }}</span></li>
                    <li class="list-group-item"><span class="text-muted d-block">Address</span>{{ collect([$p->address, $p->city, $p->country])->filter()->join(', ') ?: '—' }}</li>
                    <li class="list-group-item"><span class="text-muted d-block">Emergency</span>{{ $p->emergency_contact_name ?: '—' }} {{ $p->emergency_contact_phone }} {{ $p->emergency_contact_relation ? '('.$p->emergency_contact_relation.')' : '' }}</li>
                </ul>
            </div>

            <div class="card {{ $p->allergies->isNotEmpty() ? 'border-danger' : '' }}">
                <div class="card-header"><h6 class="card-title mb-0 {{ $p->allergies->isNotEmpty() ? 'text-danger' : '' }}"><i class="ri-alarm-warning-line me-1"></i>Allergies</h6></div>
                <div class="card-body">
                    @forelse ($p->allergies as $a)
                        <span class="badge bg-{{ $a->severity === 'severe' ? 'danger' : 'warning' }}-subtle text-{{ $a->severity === 'severe' ? 'danger' : 'warning' }} me-1 mb-1">{{ $a->allergen }}</span>
                    @empty
                        <span class="text-muted fs-13">No known allergies (NKA)</span>
                    @endforelse
                </div>
            </div>

            @if ($v = $p->latestVital)
                <div class="card mb-0">
                    <div class="card-header"><h6 class="card-title mb-0"><i class="ri-heart-pulse-line me-1"></i>Latest vitals <small class="text-muted fw-normal">{{ $v->recorded_at->diffForHumans() }}</small></h6></div>
                    <div class="card-body row g-2 fs-13 text-center">
                        <div class="col-4"><div class="text-muted">BP</div><strong>{{ $v->bp ?? '—' }}</strong></div>
                        <div class="col-4"><div class="text-muted">Pulse</div><strong>{{ $v->pulse ?? '—' }}</strong></div>
                        <div class="col-4"><div class="text-muted">Temp</div><strong>{{ $v->temperature ?? '—' }}</strong></div>
                        <div class="col-4"><div class="text-muted">SpO₂</div><strong>{{ $v->spo2 ? $v->spo2.'%' : '—' }}</strong></div>
                        <div class="col-4"><div class="text-muted">Weight</div><strong>{{ $v->weight ?? '—' }}</strong></div>
                        <div class="col-4"><div class="text-muted">BMI</div><strong>{{ $v->bmi ?? '—' }}</strong></div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-xl-9">
            <div class="card mb-0">
                <div class="card-header pb-0 border-0">
                    <ul class="nav nav-tabs card-header-tabs flex-nowrap overflow-auto">
                        @foreach ($tabs as $key => $label)
                            <li class="nav-item"><button type="button" class="nav-link text-nowrap {{ $tab === $key ? 'active' : '' }}" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</button></li>
                        @endforeach
                    </ul>
                </div>
                <div class="card-body">
                    <div wire:loading.flex wire:target="tab" class="justify-content-center py-5"><div class="spinner-border text-primary"></div></div>
                    <div wire:loading.remove wire:target="tab">
                        @switch($tab)
                            @case('vitals') <livewire:tenant.emr.vitals :patient="$p" :key="'vitals-'.$p->id" /> @break
                            @case('notes') <livewire:tenant.emr.notes :patient="$p" :key="'notes-'.$p->id" /> @break
                            @case('diagnoses') <livewire:tenant.emr.diagnoses :patient="$p" :key="'dx-'.$p->id" /> @break
                            @case('allergies') <livewire:tenant.emr.allergies :patient="$p" :key="'allergy-'.$p->id" /> @break
                            @case('history') <livewire:tenant.emr.history :patient="$p" :key="'hist-'.$p->id" /> @break
                            @case('documents') <livewire:tenant.emr.documents :patient="$p" :key="'docs-'.$p->id" /> @break
                            @case('visits')
                                <h6>OPD visits</h6>
                                <div class="table-responsive mb-4">
                                    <table class="table table-hms table-sm">
                                        <thead><tr><th>Visit</th><th>Date</th><th>Doctor</th><th>Complaint</th><th>Status</th><th></th></tr></thead>
                                        <tbody>
                                            @forelse ($visits as $v)
                                                <tr><td>{{ $v->visit_no }}</td><td>{{ fmt_datetime($v->visit_date) }}</td><td>{{ $v->doctor->display_name }}</td><td>{{ $v->chief_complaint }}</td><td><x-status :value="$v->status" /></td>
                                                    <td class="text-end">@can('opd.consult')<a href="{{ route('tenant.opd.consult', $v) }}" wire:navigate class="btn btn-sm btn-light-primary">Open</a>@endcan</td></tr>
                                            @empty <x-empty-row :colspan="6" message="No OPD visits." /> @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @if (hospital()->hasModule('ipd'))
                                    <h6>Admissions</h6>
                                    <div class="table-responsive">
                                        <table class="table table-hms table-sm">
                                            <thead><tr><th>Admission</th><th>Admitted</th><th>Discharged</th><th>Doctor</th><th>Bed</th><th>Status</th></tr></thead>
                                            <tbody>
                                                @forelse ($admissions as $a)
                                                    <tr><td><a href="{{ route('tenant.ipd.show', $a) }}" wire:navigate>{{ $a->admission_no }}</a></td><td>{{ fmt_datetime($a->admitted_at) }}</td><td>{{ fmt_datetime($a->discharged_at) }}</td><td>{{ $a->doctor->display_name }}</td><td>{{ $a->bed?->label }}</td><td><x-status :value="$a->status" /></td></tr>
                                                @empty <x-empty-row :colspan="6" message="No admissions." /> @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @break
                            @case('prescriptions')
                                @forelse ($prescriptions as $rx)
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between mb-2">
                                            <div><strong>{{ $rx->prescription_no }}</strong> &middot; {{ $rx->doctor->display_name }} &middot; <span class="text-muted">{{ fmt_date($rx->created_at) }}</span></div>
                                            <div><x-status :value="$rx->status" /> <a href="{{ route('tenant.prescriptions.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light ms-1"><i class="ri-printer-line"></i></a></div>
                                        </div>
                                        <ul class="mb-0 fs-13">
                                            @foreach ($rx->items as $i)<li><strong>{{ $i->medicine_name }}</strong> {{ $i->dosage }} — {{ $i->frequency }} × {{ $i->duration }} <span class="text-muted">{{ $i->instructions }}</span></li>@endforeach
                                        </ul>
                                    </div>
                                @empty
                                    <p class="text-muted text-center py-4">No prescriptions.</p>
                                @endforelse
                                @break
                            @case('lab')
                                <div class="table-responsive">
                                    <table class="table table-hms table-sm">
                                        <thead><tr><th>Order</th><th>Date</th><th>Tests</th><th>Status</th><th></th></tr></thead>
                                        <tbody>
                                            @forelse ($labOrders as $o)
                                                <tr><td><a href="{{ route('tenant.lab.order', $o) }}" wire:navigate>{{ $o->order_no }}</a></td><td>{{ fmt_datetime($o->ordered_at) }}</td><td>{{ $o->items->pluck('test.name')->join(', ') }}</td><td><x-status :value="$o->status" /></td>
                                                    <td class="text-end">@if ($o->status === 'approved')<a href="{{ route('tenant.lab.report', $o->id) }}" target="_blank" class="btn btn-sm btn-light-success"><i class="ri-file-pdf-2-line"></i> Report</a>@endif</td></tr>
                                            @empty <x-empty-row :colspan="5" message="No lab orders." /> @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @break
                            @case('radiology')
                                <div class="table-responsive">
                                    <table class="table table-hms table-sm">
                                        <thead><tr><th>Order</th><th>Study</th><th>Date</th><th>Status</th><th></th></tr></thead>
                                        <tbody>
                                            @forelse ($radiologyOrders as $o)
                                                <tr><td><a href="{{ route('tenant.radiology.order', $o) }}" wire:navigate>{{ $o->order_no }}</a></td><td>{{ $o->test->name }}</td><td>{{ fmt_datetime($o->created_at) }}</td><td><x-status :value="$o->status" /></td>
                                                    <td class="text-end">@if (in_array($o->status, ['reported', 'approved']))<a href="{{ route('tenant.radiology.report', $o->id) }}" target="_blank" class="btn btn-sm btn-light-success"><i class="ri-file-pdf-2-line"></i></a>@endif</td></tr>
                                            @empty <x-empty-row :colspan="5" message="No imaging orders." /> @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @break
                            @case('billing')
                                <div class="table-responsive">
                                    <table class="table table-hms table-sm">
                                        <thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                                        <tbody>
                                            @forelse ($invoices as $inv)
                                                <tr><td><a href="{{ route('tenant.billing.show', $inv) }}" wire:navigate>{{ $inv->invoice_no }}</a></td><td>{{ fmt_date($inv->invoice_date) }}</td><td class="text-end">{{ money($inv->total) }}</td><td class="text-end">{{ money($inv->paid_amount) }}</td><td class="text-end">{{ money(max(0, $inv->balance)) }}</td><td><x-status :value="$inv->status" /></td></tr>
                                            @empty <x-empty-row :colspan="6" message="No invoices." /> @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @break
                            @default
                                <h6 class="mb-3">Clinical timeline</h6>
                                <ul class="list-unstyled mb-0">
                                    @forelse ($timeline as $t)
                                        <li class="d-flex gap-3 pb-3">
                                            <div class="avatar-item avatar avatar-title bg-{{ $t['color'] }}-subtle text-{{ $t['color'] }} flex-shrink-0"><i class="{{ $t['icon'] }}"></i></div>
                                            <div class="flex-grow-1 border-bottom pb-3">
                                                <div class="d-flex justify-content-between">
                                                    <strong>@if (! empty($t['url']))<a href="{{ $t['url'] }}" wire:navigate>{{ $t['title'] }}</a>@else{{ $t['title'] }}@endif</strong>
                                                    <small class="text-muted">{{ fmt_datetime($t['date']) }}</small>
                                                </div>
                                                <div class="fs-13">{{ $t['text'] }} <x-status :value="$t['status']" /></div>
                                            </div>
                                        </li>
                                    @empty
                                        <li class="text-muted text-center py-4">No clinical activity yet.</li>
                                    @endforelse
                                </ul>
                        @endswitch
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
