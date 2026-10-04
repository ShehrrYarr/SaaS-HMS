<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\WithTable;
use App\Models\BloodBag;
use App\Models\BloodCrossmatch;
use App\Models\BloodRequest;
use App\Models\Patient;
use App\Services\BloodBankService;
use App\Support\Sequence;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Blood Requests')] class extends Component
{
    use SearchesPatients, WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at'];

    #[Url]
    public string $status = 'pending';

    public bool $showForm = false;

    public array $form = [];

    public ?int $activeId = null;

    public ?string $bagId = null;

    public string $result = 'compatible';

    public string $cmNotes = '';

    public string $charge = '';

    public function create(): void
    {
        abort_unless(auth()->user()->canAny(['bloodbank.manage', 'bloodbank.view']), 403);
        $this->form = ['patient_id' => null, 'blood_group' => '', 'component' => 'prbc', 'units' => 1, 'priority' => 'routine', 'notes' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function updatedFormPatientId($id): void
    {
        $this->form['blood_group'] = (string) Patient::find($id)?->blood_group;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->canAny(['bloodbank.manage', 'bloodbank.view']), 403);
        $this->validate([
            'form.patient_id' => ['required', tenant_exists('patients')],
            'form.blood_group' => 'required|in:'.implode(',', config('hms.blood_groups')),
            'form.component' => 'required|in:'.implode(',', array_keys(BloodBag::COMPONENTS)),
            'form.units' => 'required|integer|min:1|max:20',
            'form.priority' => 'required|in:routine,urgent',
            'form.notes' => 'nullable|string|max:255',
        ], [], ['form.patient_id' => 'patient']);
        $patient = Patient::with('currentAdmission')->findOrFail($this->form['patient_id']);
        BloodRequest::create($this->form + ['request_no' => Sequence::code('blood-request', 'BRQ'), 'ipd_admission_id' => $patient->currentAdmission?->id, 'status' => 'pending', 'requested_by' => auth()->id()]);
        if (! $patient->blood_group) {
            $patient->update(['blood_group' => $this->form['blood_group']]);
        }
        $this->showForm = false;
        $this->toast('Blood request created.');
    }

    public function open(int $id): void
    {
        $this->activeId = $this->activeId === $id ? null : $id;
        $this->reset('bagId', 'cmNotes', 'charge');
        $this->result = 'compatible';
    }

    public function crossmatch(BloodBankService $bb): void
    {
        $this->authorize('bloodbank.manage');
        $this->validate(['bagId' => ['required', tenant_exists('blood_bags')], 'result' => 'required|in:compatible,incompatible', 'cmNotes' => 'nullable|string|max:255']);
        $bb->crossmatch(BloodRequest::findOrFail($this->activeId), BloodBag::findOrFail($this->bagId), $this->result, $this->cmNotes ?: null);
        $this->reset('bagId', 'cmNotes');
        $this->toast('Cross-match recorded.');
    }

    public function issue(int $crossmatchId, BloodBankService $bb): void
    {
        $this->authorize('bloodbank.manage');
        $this->validate(['charge' => 'nullable|numeric|min:0']);
        $bb->issue(BloodCrossmatch::with('request.patient', 'request.admission', 'bag')->findOrFail($crossmatchId), (float) $this->charge);
        $this->toast('Unit issued.');
    }

    public function cancel(int $id): void
    {
        $this->authorize('bloodbank.manage');
        $req = BloodRequest::findOrFail($id);
        $req->crossmatches()->whereNull('issued_at')->with('bag')->get()->each(fn ($cm) => $cm->bag->status === 'reserved' ? $cm->bag->update(['status' => 'available']) : null);
        $req->update(['status' => 'cancelled']);
        $this->toast('Request cancelled; reserved units released.', 'warning');
    }

    public function with(): array
    {
        $query = BloodRequest::with(['patient', 'admission.bed.ward', 'crossmatches.bag', 'requester'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('request_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $active = $this->activeId ? BloodRequest::find($this->activeId) : null;

        return [
            'requests' => $this->applySort($query)->paginate($this->perPage),
            'candidateBags' => $active ? BloodBag::where('status', 'available')->where('component', $active->component)->whereDate('expires_at', '>=', today())
                ->whereIn('blood_group', BloodBankService::compatibleDonorGroups($active->blood_group, $active->component))->orderBy('expires_at')->get()
                ->mapWithKeys(fn ($b) => [$b->id => "{$b->bag_no} · {$b->blood_group} · exp ".fmt_date($b->expires_at)])->all() : [],
            'groups' => config('hms.blood_groups'),
            'components' => BloodBag::COMPONENTS,
        ];
    }
}; ?>

<div>
    <x-page-header title="Blood Requests" subtitle="Cross-match & issue" :breadcrumbs="['Blood Bank' => route('tenant.bloodbank.inventory')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New request</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Request # or patient...">
            <select class="form-select w-auto" wire:model.live="status"><option value="">All</option>@foreach (['pending', 'crossmatched', 'issued', 'cancelled'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms align-middle mb-0">
                <thead class="table-light"><tr><th>Request</th><th>Patient</th><th>Group</th><th>Component</th><th>Units</th><th>Priority</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($requests as $r)
                        <tr wire:key="br-{{ $r->id }}" class="{{ $activeId === $r->id ? 'table-active' : '' }}">
                            <td class="fw-semibold">{{ $r->request_no }}<div class="fs-12 text-muted">{{ fmt_datetime($r->created_at) }}</div></td>
                            <td>{{ $r->patient->full_name }}<div class="fs-12 text-muted">{{ $r->admission?->bed?->label ?? 'OPD' }}</div></td>
                            <td class="text-danger fw-bold">{{ $r->blood_group }}</td><td>{{ $components[$r->component] ?? $r->component }}</td>
                            <td>{{ $r->crossmatches->whereNotNull('issued_at')->count() }}/{{ $r->units }}</td>
                            <td><span class="badge {{ $r->priority === 'urgent' ? 'bg-danger' : 'bg-light text-body' }}">{{ ucfirst($r->priority) }}</span></td>
                            <td><x-status :value="$r->status" /></td>
                            <td class="text-end text-nowrap">
                                @if (in_array($r->status, ['pending', 'crossmatched']))
                                    <button class="btn btn-sm btn-primary" wire:click="open({{ $r->id }})">{{ $activeId === $r->id ? 'Close' : 'Process' }}</button>
                                    @can('bloodbank.manage')<button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Cancel request?', () => $wire.cancel({{ $r->id }}))"><i class="ri-close-line"></i></button>@endcan
                                @endif
                            </td>
                        </tr>
                        @if ($activeId === $r->id)
                            <tr class="table-active">
                                <td colspan="8">
                                    <div class="row g-3 p-2">
                                        <div class="col-md-6">
                                            <h6>Cross-match a unit</h6>
                                            @can('bloodbank.manage')
                                                <x-form.select label="Compatible available bags (FEFO)" model="bagId" :options="$candidateBags" placeholder="{{ $candidateBags ? 'Choose bag' : 'No compatible stock' }}" />
                                                <div class="d-flex gap-2">
                                                    <select class="form-select" wire:model="result"><option value="compatible">Compatible</option><option value="incompatible">Incompatible</option></select>
                                                    <input type="text" class="form-control" placeholder="Notes" wire:model="cmNotes">
                                                    <button class="btn btn-info" wire:click="crossmatch">Save</button>
                                                </div>
                                                @error('bag')<div class="text-danger fs-12 mt-1">{{ $message }}</div>@enderror
                                            @endcan
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Cross-matches</h6>
                                            <div class="input-group input-group-sm mb-2"><span class="input-group-text">Charge per unit</span><input type="number" step="0.01" class="form-control" wire:model="charge"></div>
                                            @forelse ($r->crossmatches as $cm)
                                                <div class="d-flex justify-content-between align-items-center border-bottom py-1 fs-13">
                                                    <span>{{ $cm->bag->bag_no }} ({{ $cm->bag->blood_group }}) · <x-status :value="$cm->result" /> @if ($cm->issued_at)<span class="badge bg-success">Issued {{ $cm->issued_at->format('d M H:i') }}</span>@endif</span>
                                                    @if ($cm->result === 'compatible' && ! $cm->issued_at)@can('bloodbank.manage')<button class="btn btn-sm btn-success" wire:click="issue({{ $cm->id }})">Issue</button>@endcan @endif
                                                </div>
                                            @empty
                                                <p class="text-muted fs-13 mb-0">No cross-matches yet.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <x-empty-row :colspan="8" message="No requests." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $requests->links() }}</div>
    </div>

    <x-modal wire:model="showForm" title="New blood request">
        <x-form.search-select label="Patient" model="form.patient_id" search="searchPatients" live required />
        <div class="row">
            <x-form.select class="col-md-4" label="Blood group" model="form.blood_group" :options="array_combine($groups, $groups)" required />
            <x-form.select class="col-md-8" label="Component" model="form.component" :options="$components" :placeholder="false" />
            <x-form.input class="col-md-4" label="Units" model="form.units" type="number" min="1" />
            <x-form.select class="col-md-4" label="Priority" model="form.priority" :options="['routine' => 'Routine', 'urgent' => 'Urgent']" :placeholder="false" />
            <x-form.input class="col-12" label="Indication / notes" model="form.notes" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Create request</button></x-slot:footer>
    </x-modal>
</div>
