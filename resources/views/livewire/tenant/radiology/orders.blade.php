<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\Patient;
use App\Models\RadiologyOrder;
use App\Models\RadiologyTest;
use App\Models\Staff;
use App\Services\DiagnosticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Imaging Orders')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at', 'scheduled_at'];

    #[Url]
    public string $status = 'active';

    #[Url]
    public string $modality = '';

    public bool $showForm = false;

    public array $form = [];

    public function create(): void
    {
        $this->authorize('radiology.order');
        $this->form = ['patient_id' => null, 'doctor_id' => null, 'tests' => [], 'priority' => 'routine', 'history' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(DiagnosticsService $d): void
    {
        $this->authorize('radiology.order');
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.doctor_id' => ['nullable', doctor_exists()],
            'form.tests' => 'required|array|min:1',
            'form.priority' => 'required|in:routine,urgent,stat',
            'form.history' => 'nullable|string|max:1000',
        ], [], ['form.patient_id' => 'patient', 'form.tests' => 'studies']);
        $orders = $d->orderRadiology(Patient::findOrFail($this->form['patient_id']), $this->form['tests'], $this->form['doctor_id'] ? Staff::find($this->form['doctor_id']) : null, null, $this->form['priority'], $this->form['history'] ?: null);
        $this->showForm = false;
        $this->toast(count($orders).' imaging order(s) created.');
    }

    public function with(): array
    {
        $query = RadiologyOrder::with(['patient', 'doctor', 'test'])
            ->when($this->status === 'active', fn ($q) => $q->whereIn('status', ['ordered', 'scheduled', 'performed', 'reported']))
            ->when($this->status && $this->status !== 'active', fn ($q) => $q->where('status', $this->status))
            ->when($this->modality, fn ($q) => $q->whereHas('test', fn ($t) => $t->where('modality', $this->modality)))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('order_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        return [
            'orders' => $this->applySort($query)->paginate($this->perPage),
            'counts' => RadiologyOrder::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status'),
            'catalog' => RadiologyTest::where('is_active', true)->orderBy('modality')->orderBy('name')->get()->groupBy('modality'),
            'modalities' => RadiologyTest::MODALITIES,
            'doctors' => $this->doctorOptions(),
        ];
    }
}; ?>

<div wire:poll.30s>
    <x-page-header title="Imaging Orders" subtitle="Radiology worklist">
        @can('radiology.order')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New imaging order</button>@endcan
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach (['active' => 'Active', 'ordered' => 'Ordered', 'scheduled' => 'Scheduled', 'performed' => 'Awaiting report', 'reported' => 'Awaiting approval', 'approved' => 'Final', 'cancelled' => 'Cancelled', '' => 'All'] as $k => $l)
            <button class="btn btn-sm {{ $status === $k ? 'btn-primary' : 'btn-light' }}" wire:click="$set('status', '{{ $k }}')">{{ $l }} @if ($k && $k !== 'active')<span class="badge bg-white text-dark ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</button>
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Order # or patient...">
            <select class="form-select w-auto" wire:model.live="modality"><option value="">All modalities</option>@foreach ($modalities as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Order</th><x-th field="created_at" :sort="$sortField" :dir="$sortDirection">Ordered</x-th><th>Patient</th><th>Study</th><th>Referred by</th><x-th field="scheduled_at" :sort="$sortField" :dir="$sortDirection">Scheduled</x-th><th>Priority</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="ro-{{ $o->id }}">
                            <td class="fw-semibold">{{ $o->order_no }}</td>
                            <td class="text-nowrap fs-13">{{ fmt_datetime($o->created_at) }}</td>
                            <td><a href="{{ route('tenant.patients.show', $o->patient) }}" wire:navigate>{{ $o->patient->full_name }}</a><div class="fs-12 text-muted">{{ $o->patient->uhid }} · {{ $o->patient->age_gender }}</div></td>
                            <td>{{ $o->test->name }}<div class="fs-12 text-muted">{{ $modalities[$o->test->modality] ?? $o->test->modality }}</div></td>
                            <td class="fs-13">{{ $o->doctor?->display_name ?? 'Self' }}</td>
                            <td class="fs-13">{{ fmt_datetime($o->scheduled_at) }}</td>
                            <td><span class="badge {{ $o->priority === 'stat' ? 'bg-danger' : ($o->priority === 'urgent' ? 'bg-warning' : 'bg-light text-body') }}">{{ strtoupper($o->priority) }}</span></td>
                            <td><x-status :value="$o->status" /></td>
                            <td class="text-end"><a href="{{ route('tenant.radiology.order', $o) }}" wire:navigate class="btn btn-sm btn-primary">Open</a></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="9" message="No imaging orders." icon="ri-scan-2-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $orders->links() }}</div>
    </div>

    <x-modal wire:model="showForm" title="New imaging order" size="lg">
        <x-form.search-select label="Patient" model="form.patient_id" search="searchPatients" required />
        <div class="row">
            <x-form.search-select class="col-md-6" label="Referring doctor" model="form.doctor_id" :options="$doctors" placeholder="Self" />
            <x-form.select class="col-md-6" label="Priority" model="form.priority" :options="['routine' => 'Routine', 'urgent' => 'Urgent', 'stat' => 'STAT']" :placeholder="false" />
        </div>
        <label class="form-label">Studies <span class="text-danger">*</span></label>
        <div class="border rounded p-2 mb-2" style="max-height: 240px; overflow-y: auto;">
            @foreach ($catalog as $mod => $group)
                <div class="fw-semibold fs-12 text-muted text-uppercase mt-2">{{ $modalities[$mod] ?? $mod }}</div>
                @foreach ($group as $t)
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="rt{{ $t->id }}" value="{{ $t->id }}" wire:model="form.tests"><label class="form-check-label" for="rt{{ $t->id }}">{{ $t->name }} <span class="text-muted fs-12">· {{ money($t->price) }}</span></label></div>
                @endforeach
            @endforeach
        </div>
        @error('form.tests')<div class="text-danger fs-12">{{ $message }}</div>@enderror
        <x-form.textarea label="Clinical history / indication" model="form.history" rows="2" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Create &amp; bill</button></x-slot:footer>
    </x-modal>
</div>
