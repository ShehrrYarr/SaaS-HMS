<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\Bed;
use App\Models\IpdAdmission;
use App\Models\Ward;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('IPD Admissions')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'admitted_at';

    protected array $sortable = ['admitted_at', 'discharged_at'];

    #[Url]
    public string $status = 'admitted';

    #[Url]
    public string $ward = '';

    #[Url]
    public string $doctor = '';

    public function with(): array
    {
        $query = IpdAdmission::with(['patient', 'doctor', 'bed.ward', 'tpa'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->ward, fn ($q) => $q->whereHas('bed', fn ($b) => $b->where('ward_id', $this->ward)))
            ->when($this->doctor, fn ($q) => $q->where('doctor_id', $this->doctor))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('admission_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        return [
            'admissions' => $this->applySort($query)->paginate($this->perPage),
            'wards' => Ward::orderBy('name')->pluck('name', 'id'),
            'doctors' => $this->doctorOptions(),
            'stats' => [
                'admitted' => IpdAdmission::where('status', 'admitted')->count(),
                'today' => IpdAdmission::whereDate('admitted_at', today())->count(),
                'discharged' => IpdAdmission::whereDate('discharged_at', today())->count(),
                'free' => Bed::where('status', 'available')->count(),
            ],
        ];
    }
}; ?>

<div>
    <x-page-header title="IPD Admissions" subtitle="Inpatients">
        <a href="{{ route('tenant.ipd.beds') }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-layout-grid-line me-1"></i>Bed matrix</a>
        @can('ipd.admit')<a href="{{ route('tenant.ipd.admit') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-hotel-bed-line me-1"></i>Admit patient</a>@endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Currently admitted" :value="$stats['admitted']" icon="ri-hotel-bed-line" color="warning" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Admitted today" :value="$stats['today']" icon="ri-login-box-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Discharged today" :value="$stats['discharged']" icon="ri-logout-box-r-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Free beds" :value="$stats['free']" icon="ri-checkbox-blank-circle-line" color="info" :href="route('tenant.ipd.beds')" /></div>
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Admission # or patient...">
            <select class="form-select w-auto" wire:model.live="status"><option value="">All</option><option value="admitted">Admitted</option><option value="discharged">Discharged</option><option value="cancelled">Cancelled</option></select>
            <select class="form-select w-auto" wire:model.live="ward"><option value="">All wards</option>@foreach ($wards as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="doctor"><option value="">All doctors</option>@foreach ($doctors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Admission</th><th>Patient</th><th>Bed</th><th>Doctor</th><x-th field="admitted_at" :sort="$sortField" :dir="$sortDirection">Admitted</x-th><th>LOS</th><th>Payer</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($admissions as $a)
                        <tr wire:key="adm-{{ $a->id }}">
                            <td class="fw-semibold">{{ $a->admission_no }}<div class="fs-12 text-muted">{{ label($a->admission_type) }}</div></td>
                            <td><a href="{{ route('tenant.patients.show', $a->patient) }}" wire:navigate>{{ $a->patient->full_name }}</a><div class="fs-12 text-muted">{{ $a->patient->uhid }} · {{ $a->patient->age_gender }}</div></td>
                            <td>{{ $a->bed?->label ?? '—' }}</td>
                            <td>{{ $a->doctor->display_name }}</td>
                            <td class="text-nowrap">{{ fmt_datetime($a->admitted_at) }}</td>
                            <td>{{ $a->lengthOfStay() }}d</td>
                            <td>{{ $a->tpa?->name ?? 'Self' }}</td>
                            <td><x-status :value="$a->status" /></td>
                            <td class="text-end"><a href="{{ route('tenant.ipd.show', $a) }}" wire:navigate class="btn btn-sm btn-light-primary">Open</a></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="9" message="No admissions found." icon="ri-hotel-bed-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $admissions->links() }}</div>
    </div>
</div>
