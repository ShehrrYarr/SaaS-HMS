<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Patient;
use App\Models\Vital;
use Illuminate\Database\Eloquent\Model;
use Livewire\Volt\Component;

/** Vitals tracking. Embedded in EMR, OPD consultation, IPD & nurse station. */
new class extends Component
{
    use Toasts;

    public Patient $patient;

    public ?Model $visitable = null;

    public bool $compact = false;

    public array $form = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->form = array_fill_keys(['bp_systolic', 'bp_diastolic', 'pulse', 'temperature', 'respiratory_rate', 'spo2', 'weight', 'height', 'blood_sugar', 'pain_score', 'notes'], '');
        $this->form['height'] = (string) ($this->patient->latestVital?->height ?? '');
    }

    public function save(): void
    {
        abort_unless(auth()->user()->canAny(['emr.manage', 'ipd.vitals', 'opd.consult']), 403);

        $data = $this->validate([
            'form.bp_systolic' => 'nullable|integer|between:40,300',
            'form.bp_diastolic' => 'nullable|integer|between:20,200',
            'form.pulse' => 'nullable|integer|between:20,250',
            'form.temperature' => 'nullable|numeric|between:30,45',
            'form.respiratory_rate' => 'nullable|integer|between:4,80',
            'form.spo2' => 'nullable|integer|between:40,100',
            'form.weight' => 'nullable|numeric|between:0.3,400',
            'form.height' => 'nullable|numeric|between:20,260',
            'form.blood_sugar' => 'nullable|numeric|between:10,1000',
            'form.pain_score' => 'nullable|integer|between:0,10',
            'form.notes' => 'nullable|string|max:255',
        ], [], [
            'form.temperature' => 'temperature (°C)', 'form.bp_systolic' => 'systolic BP', 'form.bp_diastolic' => 'diastolic BP',
            'form.spo2' => 'SpO₂', 'form.weight' => 'weight (kg)', 'form.height' => 'height (cm)', 'form.blood_sugar' => 'blood sugar',
        ])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if (count(array_filter($data, fn ($v) => $v !== null)) === 0) {
            $this->addError('form.bp_systolic', 'Enter at least one measurement.');

            return;
        }

        Vital::create($data + [
            'patient_id' => $this->patient->id,
            'visitable_type' => $this->visitable?->getMorphClass(),
            'visitable_id' => $this->visitable?->getKey(),
            'recorded_by' => auth()->id(),
            'recorded_at' => now(),
        ]);

        $this->resetForm();
        $this->toast('Vitals recorded.');
        $this->dispatch('vitals-saved');
    }

    public function with(): array
    {
        $vitals = $this->patient->vitals()->with('recorder')
            ->when($this->visitable, fn ($q) => $q->where('visitable_type', $this->visitable->getMorphClass())->where('visitable_id', $this->visitable->getKey()))
            ->latest('recorded_at')->limit($this->compact ? 5 : 30)->get();

        $trend = $vitals->sortBy('recorded_at')->values();

        return [
            'vitals' => $vitals,
            'canAdd' => auth()->user()->canAny(['emr.manage', 'ipd.vitals', 'opd.consult']),
            'chart' => $trend->count() > 1 && ! $this->compact ? [
                'chart' => ['type' => 'line', 'height' => 240],
                'series' => [
                    ['name' => 'Systolic', 'data' => $trend->pluck('bp_systolic')],
                    ['name' => 'Diastolic', 'data' => $trend->pluck('bp_diastolic')],
                    ['name' => 'Pulse', 'data' => $trend->pluck('pulse')],
                ],
                'xaxis' => ['categories' => $trend->map(fn ($v) => $v->recorded_at->format('d M H:i'))],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
            ] : null,
        ];
    }
}; ?>

<div>
    @if ($canAdd)
        <form wire:submit="save" class="border rounded p-3 mb-4 bg-body-tertiary">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label fs-12 mb-1">BP (mmHg)</label>
                    <div class="input-group input-group-sm">
                        <input type="number" class="form-control @error('form.bp_systolic') is-invalid @enderror" placeholder="120" wire:model="form.bp_systolic">
                        <span class="input-group-text">/</span>
                        <input type="number" class="form-control @error('form.bp_diastolic') is-invalid @enderror" placeholder="80" wire:model="form.bp_diastolic">
                    </div>
                </div>
                @foreach (['pulse' => 'Pulse (bpm)', 'temperature' => 'Temp (°C)', 'respiratory_rate' => 'Resp. rate', 'spo2' => 'SpO₂ %', 'weight' => 'Weight (kg)', 'height' => 'Height (cm)', 'blood_sugar' => 'Sugar (mg/dL)', 'pain_score' => 'Pain (0-10)'] as $field => $label)
                    <div class="col-6 col-md-{{ $compact ? 3 : 1 }} {{ $compact ? '' : 'col-lg' }}">
                        <label class="form-label fs-12 mb-1">{{ $label }}</label>
                        <input type="number" step="any" class="form-control form-control-sm @error('form.'.$field) is-invalid @enderror" wire:model="form.{{ $field }}">
                    </div>
                @endforeach
                <div class="col-md-{{ $compact ? 9 : 10 }}">
                    <input type="text" class="form-control form-control-sm" placeholder="Notes (optional)" wire:model="form.notes">
                </div>
                <div class="col-md-{{ $compact ? 3 : 2 }}"><button class="btn btn-sm btn-primary w-100"><i class="ri-add-line"></i> Record</button></div>
            </div>
            @if ($errors->any())
                <ul class="text-danger fs-12 mt-2 mb-0 ps-3">
                    @foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach
                </ul>
            @endif
        </form>
    @endif

    @if ($chart)
        <div class="mb-3" wire:key="vchart-{{ $vitals->count() }}"><div x-data="apexChart(@js($chart))" wire:ignore></div></div>
    @endif

    <div class="table-responsive">
        <table class="table table-hms table-sm mb-0">
            <thead><tr><th>Recorded</th><th>BP</th><th>Pulse</th><th>Temp</th><th>RR</th><th>SpO₂</th><th>Wt</th><th>BMI</th><th>Sugar</th><th>By</th></tr></thead>
            <tbody>
                @forelse ($vitals as $v)
                    <tr wire:key="v-{{ $v->id }}">
                        <td class="text-nowrap">{{ fmt_datetime($v->recorded_at) }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('bp')])>{{ $v->bp ?? '—' }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('pulse')])>{{ $v->pulse ?? '—' }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('temperature')])>{{ $v->temperature ?? '—' }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('respiratory_rate')])>{{ $v->respiratory_rate ?? '—' }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('spo2')])>{{ $v->spo2 ? $v->spo2.'%' : '—' }}</td>
                        <td>{{ $v->weight ?? '—' }}</td>
                        <td>{{ $v->bmi ?? '—' }}</td>
                        <td @class(['text-danger fw-semibold' => $v->abnormal('blood_sugar')])>{{ $v->blood_sugar ?? '—' }}</td>
                        <td class="fs-12 text-muted">{{ $v->recorder?->name }}</td>
                    </tr>
                @empty
                    <x-empty-row :colspan="10" message="No vitals recorded yet." icon="ri-heart-pulse-line" />
                @endforelse
            </tbody>
        </table>
    </div>
</div>
