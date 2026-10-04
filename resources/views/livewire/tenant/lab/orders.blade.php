<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\DiagnosticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Lab Orders')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'ordered_at';

    protected array $sortable = ['ordered_at'];

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $priority = '';

    public bool $showForm = false;

    public array $form = ['patient_id' => null, 'doctor_id' => null, 'tests' => [], 'priority' => 'routine', 'notes' => ''];

    public function create(): void
    {
        $this->authorize('lab.order');
        $this->form = ['patient_id' => null, 'doctor_id' => null, 'tests' => [], 'priority' => 'routine', 'notes' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(DiagnosticsService $d)
    {
        $this->authorize('lab.order');
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.doctor_id' => ['nullable', doctor_exists()],
            'form.tests' => 'required|array|min:1',
            'form.priority' => 'required|in:routine,urgent,stat',
            'form.notes' => 'nullable|string|max:500',
        ], [], ['form.patient_id' => 'patient', 'form.tests' => 'tests']);

        $order = $d->orderLab(Patient::findOrFail($this->form['patient_id']), $this->form['tests'], $this->form['doctor_id'] ? Staff::find($this->form['doctor_id']) : null, null, $this->form['priority'], $this->form['notes'] ?: null);
        session()->flash('success', "Lab order {$order->order_no} created.");

        return $this->redirect(route('tenant.lab.order', $order), navigate: true);
    }

    public function with(): array
    {
        $query = LabOrder::with(['patient', 'doctor', 'items.test'])
            ->when($this->status === 'active', fn ($q) => $q->whereIn('status', ['ordered', 'sample_collected', 'processing', 'completed']))
            ->when($this->status && $this->status !== 'active', fn ($q) => $q->where('status', $this->status))
            ->when($this->priority, fn ($q) => $q->where('priority', $this->priority))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('order_no', 'like', "%{$this->search}%")
                ->orWhereHas('items', fn ($i) => $i->where('sample_barcode', $this->search))
                ->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $counts = LabOrder::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return [
            'orders' => $this->applySort($query->orderByRaw("CASE priority WHEN 'stat' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END"))->paginate($this->perPage),
            'counts' => $counts,
            'tests' => LabTest::where('is_active', true)->with('category')->orderBy('name')->get()->groupBy(fn ($t) => $t->category?->name ?? 'Other'),
            'doctors' => $this->doctorOptions(),
        ];
    }
}; ?>

<div wire:poll.20s>
    <x-page-header title="Lab Orders" subtitle="Laboratory work queue">
        @can('lab.order')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New lab order</button>@endcan
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach (['active' => 'Active', 'ordered' => 'Awaiting sample', 'sample_collected' => 'Sample collected', 'processing' => 'Processing', 'completed' => 'Awaiting approval', 'approved' => 'Reported', 'cancelled' => 'Cancelled', '' => 'All'] as $k => $l)
            <button class="btn btn-sm {{ $status === $k ? 'btn-primary' : 'btn-light' }}" wire:click="$set('status', '{{ $k }}')">{{ $l }}
                @if ($k && $k !== 'active')<span class="badge bg-white text-dark ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</button>
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Order #, barcode or patient...">
            <select class="form-select w-auto" wire:model.live="priority"><option value="">Any priority</option><option value="stat">STAT</option><option value="urgent">Urgent</option><option value="routine">Routine</option></select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Order</th><x-th field="ordered_at" :sort="$sortField" :dir="$sortDirection">Ordered</x-th><th>Patient</th><th>Tests</th><th>Referred by</th><th>Priority</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="lo-{{ $o->id }}">
                            <td class="fw-semibold">{{ $o->order_no }}</td>
                            <td class="text-nowrap">{{ fmt_datetime($o->ordered_at) }}</td>
                            <td><a href="{{ route('tenant.patients.show', $o->patient) }}" wire:navigate>{{ $o->patient->full_name }}</a><div class="fs-12 text-muted">{{ $o->patient->uhid }} · {{ $o->patient->age_gender }}</div></td>
                            <td class="fs-13">@foreach ($o->items as $i)<span class="badge bg-{{ status_color($i->status) }}-subtle text-{{ status_color($i->status) }} me-1">{{ $i->test->code }}</span>@endforeach</td>
                            <td class="fs-13">{{ $o->doctor?->display_name ?? 'Self / walk-in' }}</td>
                            <td><span class="badge {{ $o->priority === 'stat' ? 'bg-danger' : ($o->priority === 'urgent' ? 'bg-warning' : 'bg-light text-body') }}">{{ strtoupper($o->priority) }}</span></td>
                            <td><x-status :value="$o->status" /></td>
                            <td class="text-end text-nowrap">
                                @if ($o->status === 'approved')<a href="{{ route('tenant.lab.report', $o->id) }}" target="_blank" class="btn btn-sm btn-light-success icon-btn-sm" title="Report"><i class="ri-file-pdf-2-line"></i></a>@endif
                                <a href="{{ route('tenant.lab.order', $o) }}" wire:navigate class="btn btn-sm btn-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No lab orders in this view." icon="ri-flask-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $orders->links() }}</div>
    </div>

    <x-modal wire:model="showForm" title="New lab order" size="lg">
        <x-form.search-select label="Patient" model="form.patient_id" search="searchPatients" required />
        <div class="row">
            <x-form.search-select class="col-md-6" label="Referring doctor" model="form.doctor_id" :options="$doctors" placeholder="Self / walk-in" />
            <x-form.select class="col-md-6" label="Priority" model="form.priority" :options="['routine' => 'Routine', 'urgent' => 'Urgent', 'stat' => 'STAT']" :placeholder="false" />
        </div>
        <label class="form-label">Tests <span class="text-danger">*</span></label>
        <div class="border rounded p-2 mb-2" style="max-height: 260px; overflow-y: auto;">
            @foreach ($tests as $cat => $group)
                <div class="fw-semibold fs-12 text-muted text-uppercase mt-2">{{ $cat }}</div>
                @foreach ($group as $t)
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="nt{{ $t->id }}" value="{{ $t->id }}" wire:model="form.tests">
                        <label class="form-check-label" for="nt{{ $t->id }}">{{ $t->name }} <span class="text-muted fs-12">· {{ money($t->price) }} · {{ $t->turnaround_hours }}h</span></label></div>
                @endforeach
            @endforeach
        </div>
        @error('form.tests')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
        <x-form.input label="Clinical notes" model="form.notes" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Create order &amp; bill</button></x-slot:footer>
    </x-modal>
</div>
