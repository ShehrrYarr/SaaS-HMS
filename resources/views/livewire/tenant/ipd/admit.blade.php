<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\Staff;
use App\Models\Tpa;
use App\Models\Ward;
use App\Services\IpdService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Admit Patient')] class extends Component
{
    use SearchesPatients;

    public array $form = [];

    public ?string $patientLabel = null;

    public function mount(): void
    {
        $patientId = request()->integer('patient') ?: null;
        $patient = $patientId ? Patient::find($patientId) : null;
        $this->form = [
            'patient_id' => $patient ? (string) $patient->id : null,
            'doctor_id' => request('doctor') ? (string) request()->integer('doctor') : null,
            'bed_id' => request('bed') ? (string) request()->integer('bed') : null,
            'admission_type' => 'planned', 'reason' => '', 'provisional_diagnosis' => '',
            'tpa_id' => $patient?->tpa_id ? (string) $patient->tpa_id : '', 'insurance_policy_no' => (string) $patient?->insurance_policy_no,
            'guardian_name' => (string) ($patient?->emergency_contact_name ?? ''), 'guardian_phone' => (string) ($patient?->emergency_contact_phone ?? ''),
            'deposit_amount' => '', 'expected_discharge_date' => '',
        ];
        $this->patientLabel = $this->patientLabel($patient?->id);
    }

    public function updatedFormPatientId($id): void
    {
        $p = Patient::find($id);
        if ($p) {
            $this->form['tpa_id'] = $p->tpa_id ? (string) $p->tpa_id : '';
            $this->form['insurance_policy_no'] = (string) $p->insurance_policy_no;
            $this->form['guardian_name'] = (string) $p->emergency_contact_name;
            $this->form['guardian_phone'] = (string) $p->emergency_contact_phone;
        }
    }

    public function save(IpdService $ipd)
    {
        $this->authorize('ipd.admit');
        $data = $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.doctor_id' => ['required', doctor_exists()],
            'form.bed_id' => ['required', Rule::exists('beds', 'id')->where('hospital_id', hospital()->id)],
            'form.admission_type' => 'required|in:emergency,planned,transfer,daycare',
            'form.reason' => 'nullable|string|max:255',
            'form.provisional_diagnosis' => 'nullable|string|max:255',
            'form.tpa_id' => ['nullable', tenant_exists('tpas')],
            'form.insurance_policy_no' => 'nullable|string|max:100',
            'form.guardian_name' => 'nullable|string|max:120',
            'form.guardian_phone' => 'nullable|string|max:30',
            'form.deposit_amount' => 'nullable|numeric|min:0',
            'form.expected_discharge_date' => 'nullable|date|after_or_equal:today',
        ], [], ['form.patient_id' => 'patient', 'form.doctor_id' => 'doctor', 'form.bed_id' => 'bed'])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $doctor = Staff::doctors()->findOrFail($data['doctor_id']);
        $data['department_id'] = $doctor->department_id;

        $admission = $ipd->admit(Patient::findOrFail($data['patient_id']), $data);
        session()->flash('success', "Admitted · {$admission->admission_no} · bed {$admission->bed->label}");

        return $this->redirect(route('tenant.ipd.show', $admission), navigate: true);
    }

    public function with(): array
    {
        return [
            'doctors' => $this->doctorOptions(),
            'tpas' => Tpa::where('is_active', true)->pluck('name', 'id'),
            'wards' => Ward::where('is_active', true)->with(['beds' => fn ($q) => $q->orderBy('bed_no')])->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Admit Patient" subtitle="New admission" :breadcrumbs="['IPD' => route('tenant.ipd.index')]" />
    <form wire:submit="save">
        <div class="row g-4">
            <div class="col-xl-6">
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">Admission details</h5></div>
                    <div class="card-body row">
                        <x-form.search-select class="col-12" label="Patient" model="form.patient_id" search="searchPatients" :selected-label="$patientLabel" live required />
                        <x-form.search-select class="col-md-7" label="Attending doctor" model="form.doctor_id" :options="$doctors" required />
                        <x-form.select class="col-md-5" label="Admission type" model="form.admission_type" :options="['planned' => 'Planned', 'emergency' => 'Emergency', 'transfer' => 'Transfer-in', 'daycare' => 'Day care']" :placeholder="false" />
                        <x-form.input class="col-12" label="Reason for admission" model="form.reason" />
                        <x-form.input class="col-12" label="Provisional diagnosis" model="form.provisional_diagnosis" />
                        <x-form.select class="col-md-6" label="Insurance / TPA" model="form.tpa_id" :options="$tpas" placeholder="Self-pay" />
                        <x-form.input class="col-md-6" label="Policy no." model="form.insurance_policy_no" />
                        <x-form.input class="col-md-6" label="Guardian / attendant" model="form.guardian_name" />
                        <x-form.input class="col-md-6" label="Guardian phone" model="form.guardian_phone" />
                        <x-form.input class="col-md-6" label="Advance deposit" model="form.deposit_amount" type="number" step="0.01" :prepend="currency_symbol()" />
                        <x-form.input class="col-md-6" label="Expected discharge" model="form.expected_discharge_date" type="date" />
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Select bed <span class="text-danger">*</span></h5></div>
                    <div class="card-body" style="max-height: 560px; overflow-y: auto;">
                        @error('form.bed_id')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                        @foreach ($wards as $ward)
                            <h6 class="mt-2">{{ $ward->name }} <small class="text-muted fw-normal">· {{ money($ward->charge_per_day) }}/day · {{ $ward->beds->where('status', 'available')->count() }} free</small></h6>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                @foreach ($ward->beds as $bed)
                                    @php $free = in_array($bed->status, ['available', 'reserved']); @endphp
                                    <input type="radio" class="btn-check" id="bed{{ $bed->id }}" value="{{ $bed->id }}" wire:model="form.bed_id" @disabled(! $free)>
                                    <label class="btn btn-sm {{ $free ? 'btn-outline-success' : 'btn-outline-secondary' }}" for="bed{{ $bed->id }}" title="{{ label($bed->status) }}">
                                        <i class="ri-hotel-bed-line"></i> {{ $bed->bed_no }}
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
                <button class="btn btn-primary w-100" wire:loading.attr="disabled"><i class="ri-hotel-bed-fill me-1"></i>Admit patient</button>
            </div>
        </div>
    </form>
</div>
