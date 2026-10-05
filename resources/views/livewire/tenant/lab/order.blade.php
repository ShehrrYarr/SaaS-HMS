<?php

use App\Livewire\Concerns\Toasts;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Services\DiagnosticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Lab Order')] class extends Component
{
    use Toasts;

    public LabOrder $labOrder;

    public ?int $editing = null;

    /** @var array<int,string> parameter_id => value */
    public array $values = [];

    public string $remarks = '';

    public function mount(LabOrder $labOrder): void
    {
        $this->labOrder = $labOrder;
    }

    public function collect(int $itemId, DiagnosticsService $d): void
    {
        $this->authorize('lab.collect_sample');
        $item = $this->labOrder->items()->findOrFail($itemId);
        $d->collectSample($item);
        $this->toast("Sample collected · barcode {$item->fresh()->sample_barcode}");
    }

    public function collectAll(DiagnosticsService $d): void
    {
        $this->authorize('lab.collect_sample');
        foreach ($this->labOrder->items()->where('status', 'pending')->get() as $item) {
            $d->collectSample($item);
        }
        $this->toast('All samples collected.');
        $this->dispatch('print', url: route('tenant.lab.labels', $this->labOrder->id), title: 'Samples collected');
    }

    public function edit(int $itemId): void
    {
        $this->authorize('lab.enter_results');
        $item = $this->labOrder->items()->with('results', 'test.parameters')->findOrFail($itemId);
        $this->editing = $item->id;
        $this->values = $item->test->parameters->mapWithKeys(fn ($p) => [$p->id => (string) ($item->results->firstWhere('lab_test_parameter_id', $p->id)?->value ?? '')])->all();
        $this->remarks = (string) $item->remarks;
    }

    public function saveResults(bool $complete, DiagnosticsService $d): void
    {
        $this->authorize('lab.enter_results');
        $this->validate(['values.*' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:1000']);
        $item = $this->labOrder->items()->findOrFail($this->editing);
        if ($complete && collect($this->values)->filter(fn ($v) => $v !== '' && $v !== null)->isEmpty()) {
            $this->addError('values', 'Enter at least one result.');

            return;
        }
        $d->saveResults($item, $this->values, $complete, $this->remarks ?: null);
        $this->editing = null;
        $this->toast($complete ? 'Results saved – awaiting approval.' : 'Draft saved.');
    }

    public function approve(int $itemId, DiagnosticsService $d): void
    {
        $this->authorize('lab.approve_reports');
        $d->approve($this->labOrder->items()->findOrFail($itemId));
        $this->toast('Result approved.');
    }

    public function approveAll(DiagnosticsService $d): void
    {
        $this->authorize('lab.approve_reports');
        foreach ($this->labOrder->items()->where('status', 'completed')->get() as $item) {
            $d->approve($item);
        }
        $this->toast('All results approved – report released.');
    }

    public function cancelItem(int $itemId): void
    {
        abort_unless(auth()->user()->canAny(['lab.order', 'lab.enter_results']), 403);
        $item = $this->labOrder->items()->whereIn('status', ['pending', 'collected'])->findOrFail($itemId);
        $item->update(['status' => 'cancelled']);
        $this->labOrder->refreshStatus();
        $this->toast('Test cancelled.', 'warning');
    }

    public function with(): array
    {
        $order = $this->labOrder->load(['patient', 'doctor', 'orderedBy', 'approver', 'items.test.parameters', 'items.results', 'items.collector', 'items.enteredBy', 'items.approver', 'items.device']);
        $editingItem = $this->editing ? $order->items->firstWhere('id', $this->editing) : null;
        $flags = [];
        if ($editingItem) {
            $d = app(DiagnosticsService::class);
            foreach ($editingItem->test->parameters as $p) {
                $flags[$p->id] = $d->flag($p, $this->values[$p->id] ?? null, $order->patient->gender);
            }
        }

        return ['order' => $order, 'editingItem' => $editingItem, 'flags' => $flags];
    }
}; ?>

<div>
    <x-page-header :title="'Lab order '.$order->order_no" :subtitle="$order->patient->full_name" :breadcrumbs="['Lab Orders' => route('tenant.lab.orders')]">
        <x-status :value="$order->status" />
        @if ($order->items->contains('status', 'pending'))
            @can('lab.collect_sample')<button class="btn btn-sm btn-info" wire:click="collectAll"><i class="ri-test-tube-line me-1"></i>Collect all samples</button>@endcan
        @endif
        @if ($order->items->whereNotNull('sample_barcode')->isNotEmpty())
            <a href="{{ route('tenant.lab.labels', $order->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-barcode-line me-1"></i>Labels</a>
        @endif
        @if ($order->items->contains('status', 'completed'))
            @can('lab.approve_reports')<button class="btn btn-sm btn-success" x-on:click="$confirm('Approve & sign all completed results?', () => $wire.approveAll(), { color: 'success', confirmText: 'Approve' })"><i class="ri-shield-check-line me-1"></i>Approve all</button>@endcan
        @endif
        @if ($order->status === 'approved')
            <a href="{{ route('tenant.lab.report', $order->id) }}" target="_blank" class="btn btn-sm btn-primary"><i class="ri-file-pdf-2-line me-1"></i>Report</a>
            @if (hospital()->lab_cert_path)<a href="{{ route('tenant.lab.report', ['orderId' => $order->id, 'signed' => 1]) }}" target="_blank" class="btn btn-sm btn-light-primary"><i class="ri-quill-pen-line me-1"></i>Digitally signed PDF</a>@endif
        @endif
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-4 fs-13">
            <div><span class="text-muted d-block">Patient</span><a href="{{ route('tenant.patients.show', $order->patient) }}" wire:navigate class="fw-semibold">{{ $order->patient->full_name }}</a> · {{ $order->patient->uhid }} · {{ $order->patient->age_gender }}</div>
            <div><span class="text-muted d-block">Referred by</span>{{ $order->doctor?->display_name ?? 'Self' }}</div>
            <div><span class="text-muted d-block">Ordered</span>{{ fmt_datetime($order->ordered_at) }} by {{ $order->orderedBy?->name }}</div>
            <div><span class="text-muted d-block">Priority</span><span class="badge {{ $order->priority === 'stat' ? 'bg-danger' : ($order->priority === 'urgent' ? 'bg-warning' : 'bg-light text-body') }}">{{ strtoupper($order->priority) }}</span></div>
            @if ($order->clinical_notes)<div><span class="text-muted d-block">Clinical notes</span>{{ $order->clinical_notes }}</div>@endif
        </div>
    </div>

    @foreach ($order->items as $item)
        <div class="card" wire:key="li-{{ $item->id }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="mb-0">{{ $item->test->name }} <small class="text-muted">({{ $item->test->code }} · {{ label($item->test->sample_type) }}{{ $item->test->container ? ' · '.$item->test->container : '' }})</small></h6>
                    <small class="text-muted">
                        @if ($item->sample_barcode)<i class="ri-barcode-line"></i> {{ $item->sample_barcode }} · collected {{ fmt_datetime($item->sample_collected_at) }} by {{ $item->collector?->name }}@endif
                        @if ($item->results_entered_at) · results {{ fmt_datetime($item->results_entered_at) }} by {{ $item->enteredBy?->name ?? $item->device?->name }}@endif
                        @if ($item->approved_at) · approved by {{ $item->approver?->name }}@endif
                    </small>
                </div>
                <div class="d-flex gap-1 align-items-center">
                    <x-status :value="$item->status" />
                    @if ($item->status === 'pending')
                        @can('lab.collect_sample')<button class="btn btn-sm btn-info" wire:click="collect({{ $item->id }})">Collect sample</button>@endcan
                    @endif
                    @if (in_array($item->status, ['collected', 'processing', 'completed']) && $editing !== $item->id)
                        @can('lab.enter_results')<button class="btn btn-sm btn-primary" wire:click="edit({{ $item->id }})">{{ $item->status === 'completed' ? 'Edit results' : 'Enter results' }}</button>@endcan
                    @endif
                    @if ($item->status === 'completed')
                        @can('lab.approve_reports')<button class="btn btn-sm btn-success" wire:click="approve({{ $item->id }})">Approve</button>@endcan
                    @endif
                    @if (in_array($item->status, ['pending', 'collected']))
                        <button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Cancel {{ addslashes($item->test->name) }}?', () => $wire.cancelItem({{ $item->id }}))">Cancel</button>
                    @endif
                </div>
            </div>

            @if ($editing === $item->id && $editingItem)
                <div class="card-body">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Parameter</th><th style="width: 220px;">Result</th><th>Unit</th><th>Reference</th><th>Flag</th></tr></thead>
                        <tbody>
                            @foreach ($editingItem->test->parameters->sortBy('sort_order') as $p)
                                <tr>
                                    <td>{{ $p->name }}</td>
                                    <td>
                                        @if ($p->result_type === 'option')
                                            <select class="form-select form-select-sm" wire:model.live="values.{{ $p->id }}"><option value="">—</option>@foreach ($p->options ?? [] as $opt)<option>{{ $opt }}</option>@endforeach</select>
                                        @else
                                            <input type="{{ $p->result_type === 'numeric' ? 'text' : 'text' }}" inputmode="{{ $p->result_type === 'numeric' ? 'decimal' : 'text' }}" class="form-control form-control-sm" wire:model.live="values.{{ $p->id }}">
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $p->unit }}</td>
                                    <td class="text-muted fs-13">{{ $p->rangeText($order->patient->gender) }}</td>
                                    <td>@if ($f = $flags[$p->id] ?? null)<span class="result-flag-{{ $f }}">{{ label($f) }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @error('values')<div class="text-danger mb-2">{{ $message }}</div>@enderror
                    <x-form.textarea label="Remarks / interpretation" model="remarks" rows="2" />
                    <div class="d-flex gap-2 justify-content-end">
                        <button class="btn btn-light" wire:click="$set('editing', null)">Cancel</button>
                        <button class="btn btn-light-primary" wire:click="saveResults(false)">Save draft</button>
                        <button class="btn btn-primary" wire:click="saveResults(true)">Save &amp; mark complete</button>
                    </div>
                </div>
            @elseif ($item->results->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Parameter</th><th>Result</th><th>Unit</th><th>Reference</th><th>Flag</th></tr></thead>
                        <tbody>
                            @foreach ($item->test->parameters->sortBy('sort_order') as $p)
                                @php $r = $item->results->firstWhere('lab_test_parameter_id', $p->id); @endphp
                                <tr><td>{{ $p->name }}</td><td class="result-flag-{{ $r?->flag }}">{{ $r?->value ?? '—' }}</td><td class="text-muted">{{ $p->unit }}</td><td class="text-muted fs-13">{{ $p->rangeText($order->patient->gender) }}</td>
                                    <td>@if ($r?->flag && $r->flag !== 'normal')<span class="badge bg-{{ status_color($r->flag) }}">{{ label($r->flag) }}</span>@endif</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($item->remarks)<div class="p-2 fs-13"><strong>Remarks:</strong> {{ $item->remarks }}</div>@endif
                </div>
            @endif
        </div>
    @endforeach
</div>
