<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\OpdVisit;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\OpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('OPD Visits')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'visit_date';

    protected array $sortable = ['visit_date', 'token_no', 'fee'];

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $doctor = '';

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    public array $form = ['patient_id' => null, 'doctor_id' => null, 'visit_type' => '', 'chief_complaint' => '', 'fee' => ''];

    public function mount(): void
    {
        $this->from = $this->from ?: today()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('opd.create');
        $this->form = ['patient_id' => null, 'doctor_id' => null, 'visit_type' => '', 'chief_complaint' => '', 'fee' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(OpdService $opd): void
    {
        $this->authorize('opd.create');
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.doctor_id' => ['required', doctor_exists()],
            'form.visit_type' => 'nullable|in:new,follow_up,emergency',
            'form.chief_complaint' => 'nullable|string|max:200',
            'form.fee' => 'nullable|numeric|min:0',
        ], [], ['form.patient_id' => 'patient', 'form.doctor_id' => 'doctor']);

        $visit = $opd->createVisit(Patient::findOrFail($this->form['patient_id']), Staff::doctors()->findOrFail($this->form['doctor_id']), array_filter([
            'visit_type' => $this->form['visit_type'] ?: null,
            'chief_complaint' => $this->form['chief_complaint'] ?: null,
            'fee' => $this->form['fee'] === '' ? null : $this->form['fee'],
        ], fn ($v) => $v !== null));

        $this->showForm = false;
        $this->toast("Visit {$visit->visit_no} created · token #{$visit->token_no}");
        $this->dispatch('print', url: route('tenant.opd.slip', $visit->id));
    }

    public function with(): array
    {
        $query = OpdVisit::with(['patient', 'doctor', 'invoice'])
            ->when($this->from, fn ($q) => $q->whereDate('visit_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('visit_date', '<=', $this->to))
            ->when($this->doctor, fn ($q) => $q->where('doctor_id', $this->doctor))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('visit_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        return [
            'visits' => $this->applySort($query)->paginate($this->perPage),
            'doctors' => $this->doctorOptions(),
            'summary' => (clone $query)->reorder()->toBase()->selectRaw('count(*) as visits, sum(fee) as fees')->first(),
        ];
    }
}; ?>

<div>
    <x-page-header title="OPD Visits" subtitle="Outpatient register">
        @can('opd.create')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New OPD visit</button>@endcan
    </x-page-header>

    <div class="card">
        <x-table-toolbar placeholder="Visit # or patient...">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <input type="date" class="form-control w-auto" wire:model.live="to">
            <select class="form-select w-auto" wire:model.live="doctor"><option value="">All doctors</option>@foreach ($doctors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="status"><option value="">Any status</option>@foreach (['waiting', 'in_consultation', 'completed', 'cancelled'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>Visit</th><x-th field="visit_date" :sort="$sortField" :dir="$sortDirection">Date</x-th><x-th field="token_no" :sort="$sortField" :dir="$sortDirection">Token</x-th>
                    <th>Patient</th><th>Doctor</th><th>Type</th><x-th field="fee" :sort="$sortField" :dir="$sortDirection">Fee</x-th><th>Bill</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                    @forelse ($visits as $v)
                        <tr wire:key="ov-{{ $v->id }}">
                            <td class="fw-semibold">{{ $v->visit_no }}</td>
                            <td class="text-nowrap">{{ fmt_datetime($v->visit_date) }}</td>
                            <td>#{{ $v->token_no }}</td>
                            <td><a href="{{ route('tenant.patients.show', $v->patient) }}" wire:navigate>{{ $v->patient->full_name }}</a><div class="fs-12 text-muted">{{ $v->patient->uhid }}</div></td>
                            <td>{{ $v->doctor->display_name }}</td>
                            <td>{{ label($v->visit_type) }}</td>
                            <td>{{ money($v->fee) }}</td>
                            <td>@if ($v->invoice)<x-status :value="$v->invoice->status" />@else — @endif</td>
                            <td><x-status :value="$v->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tenant.opd.slip', $v->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm" title="Print slip"><i class="ri-printer-line"></i></a>
                                @can('opd.consult')<a href="{{ route('tenant.opd.consult', $v) }}" wire:navigate class="btn btn-sm btn-light-primary icon-btn-sm" title="Consultation"><i class="ri-stethoscope-line"></i></a>@endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="10" message="No OPD visits in this period." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="text-muted fs-13">{{ $summary->visits ?? 0 }} visits · fees {{ money($summary->fees ?? 0) }}</span>
            {{ $visits->links() }}
        </div>
    </div>

    <x-modal wire:model="showForm" title="New OPD visit">
        <x-form.search-select label="Patient" model="form.patient_id" search="searchPatients" required />
        <x-form.search-select label="Doctor" model="form.doctor_id" :options="$doctors" required />
        <div class="row">
            <x-form.select class="col-md-6" label="Visit type" model="form.visit_type" :options="['new' => 'New', 'follow_up' => 'Follow-up', 'emergency' => 'Emergency']" placeholder="Auto-detect" />
            <x-form.input class="col-md-6" label="Fee override" model="form.fee" type="number" step="0.01" placeholder="Doctor's fee" />
        </div>
        <x-form.input label="Chief complaint" model="form.chief_complaint" />
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-primary" wire:click="save">Create &amp; issue token</button>
        </x-slot:footer>
    </x-modal>
</div>
