<?php

use App\Livewire\Concerns\WithTable;
use App\Models\LabDevice;
use App\Models\LabQcLog;
use App\Models\LabTest;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Quality Control')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'logged_at';

    protected array $sortable = ['logged_at'];

    public array $form = [];

    public string $chartParam = '';

    public function mount(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->form = ['lab_test_id' => '', 'lab_device_id' => '', 'parameter' => '', 'control_lot' => '', 'control_level' => 'normal', 'expected_value' => '', 'observed_value' => '', 'tolerance' => '5', 'result' => '', 'corrective_action' => ''];
    }

    public function save(): void
    {
        $this->authorize('lab.qc');
        $valid = $this->validate([
            'form.lab_test_id' => ['nullable', tenant_exists('lab_tests')],
            'form.lab_device_id' => ['nullable', tenant_exists('lab_devices')],
            'form.parameter' => 'required|string|max:100',
            'form.control_lot' => 'nullable|string|max:50',
            'form.control_level' => 'required|in:low,normal,high',
            'form.expected_value' => 'required|numeric',
            'form.observed_value' => 'required|numeric',
            'form.tolerance' => 'required|numeric|min:0|max:100',
            'form.result' => 'nullable|in:pass,warning,fail',
            'form.corrective_action' => 'nullable|string|max:500',
        ]);

        // Auto-evaluate against the % tolerance (warning between 1x and 2x tolerance) unless overridden.
        $deviation = abs((float) $this->form['observed_value'] - (float) $this->form['expected_value']) / max(abs((float) $this->form['expected_value']), 0.0001) * 100;
        $tol = (float) $this->form['tolerance'];
        $result = $this->form['result'] ?: ($deviation <= $tol ? 'pass' : ($deviation <= 2 * $tol ? 'warning' : 'fail'));

        LabQcLog::create(collect($valid['form'])->except(['tolerance', 'result'])->map(fn ($v) => $v === '' ? null : $v)->all() + [
            'result' => $result, 'logged_by' => auth()->id(), 'logged_at' => now(),
        ]);
        $this->chartParam = $this->form['parameter'];
        $this->resetForm();
        $this->toast('QC run logged: '.strtoupper($result).' (deviation '.round($deviation, 1).'%)', $result === 'fail' ? 'error' : 'success');
    }

    public function with(): array
    {
        $query = LabQcLog::with(['test', 'device', 'logger'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('parameter', 'like', "%{$this->search}%")->orWhere('control_lot', 'like', "%{$this->search}%")));
        $params = LabQcLog::distinct()->orderBy('parameter')->pluck('parameter');
        $param = $this->chartParam ?: $params->first();
        $series = $param ? LabQcLog::where('parameter', $param)->orderBy('logged_at')->limit(40)->get() : collect();

        return [
            'logs' => $this->applySort($query)->paginate($this->perPage),
            'tests' => LabTest::orderBy('name')->pluck('name', 'id'),
            'devices' => LabDevice::orderBy('name')->pluck('name', 'id'),
            'params' => $params,
            'chart' => $series->count() > 1 ? [
                'chart' => ['type' => 'line', 'height' => 260],
                'series' => [['name' => 'Observed', 'data' => $series->pluck('observed_value')->map(fn ($v) => (float) $v)], ['name' => 'Target', 'data' => $series->pluck('expected_value')->map(fn ($v) => (float) $v)]],
                'xaxis' => ['categories' => $series->map(fn ($l) => $l->logged_at->format('d M H:i'))],
                'stroke' => ['width' => [3, 1], 'dashArray' => [0, 6]],
                'markers' => ['size' => 4],
            ] : null,
            'param' => $param,
        ];
    }
}; ?>

<div>
    <x-page-header title="Quality Control" subtitle="Internal QC log (Levey-Jennings trend)" :breadcrumbs="['Lab Orders' => route('tenant.lab.orders')]" />
    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Log a QC run</h6></div>
                <div class="card-body">
                    <x-form.select label="Test" model="form.lab_test_id" :options="$tests" />
                    <x-form.select label="Analyzer / device" model="form.lab_device_id" :options="$devices" />
                    <x-form.input label="Parameter / analyte" model="form.parameter" placeholder="e.g. Glucose" required />
                    <div class="row">
                        <x-form.input class="col-6" label="Control lot" model="form.control_lot" />
                        <x-form.select class="col-6" label="Level" model="form.control_level" :options="['low' => 'Low', 'normal' => 'Normal', 'high' => 'High']" :placeholder="false" />
                        <x-form.input class="col-4" label="Target" model="form.expected_value" type="number" step="any" required />
                        <x-form.input class="col-4" label="Observed" model="form.observed_value" type="number" step="any" required />
                        <x-form.input class="col-4" label="Tol. %" model="form.tolerance" type="number" step="0.1" />
                    </div>
                    <x-form.select label="Result" model="form.result" :options="['pass' => 'Pass', 'warning' => 'Warning', 'fail' => 'Fail']" placeholder="Auto-evaluate" />
                    <x-form.textarea label="Corrective action" model="form.corrective_action" rows="2" />
                    <button class="btn btn-primary w-100" wire:click="save">Log QC run</button>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Trend · {{ $param ?? '—' }}</h6>
                    <select class="form-select form-select-sm w-auto" wire:model.live="chartParam">@foreach ($params as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select>
                </div>
                <div class="card-body">
                    @if ($chart)<div wire:key="qc-{{ $param }}-{{ $logs->total() }}"><div x-data="apexChart(@js($chart))" wire:ignore></div></div>@else<p class="text-muted text-center py-4 mb-0">Log at least two runs for a parameter to see its trend.</p>@endif
                </div>
            </div>
            <div class="card mb-0">
                <x-table-toolbar placeholder="Parameter or lot..." />
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead class="table-light"><tr><x-th field="logged_at" :sort="$sortField" :dir="$sortDirection">When</x-th><th>Parameter</th><th>Device</th><th>Lot / level</th><th>Target</th><th>Observed</th><th>Result</th><th>By</th></tr></thead>
                        <tbody>
                            @forelse ($logs as $l)
                                <tr><td class="fs-12">{{ fmt_datetime($l->logged_at) }}</td><td>{{ $l->parameter }}<div class="fs-11 text-muted">{{ $l->test?->name }}</div></td><td class="fs-12">{{ $l->device?->name ?? '—' }}</td><td class="fs-12">{{ $l->control_lot }} · {{ label($l->control_level) }}</td>
                                    <td>{{ $l->expected_value }}</td><td>{{ $l->observed_value }}</td><td><x-status :value="$l->result" /></td><td class="fs-12">{{ $l->logger?->name }}</td></tr>
                                @if ($l->corrective_action)<tr><td colspan="8" class="fs-12 text-muted border-top-0 pt-0">Action: {{ $l->corrective_action }}</td></tr>@endif
                            @empty
                                <x-empty-row :colspan="8" message="No QC runs logged." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $logs->links() }}</div>
            </div>
        </div>
    </div>
</div>
