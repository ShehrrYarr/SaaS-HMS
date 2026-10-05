<?php

use App\Livewire\Concerns\Toasts;
use App\Models\LabDevice;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Lab Devices')] class extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public ?string $revealedToken = null;

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'manufacturer' => '', 'model' => '', 'serial_no' => '', 'protocol' => 'api', 'notes' => '', 'is_active' => true];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = LabDevice::findOrFail($id)->only(['name', 'manufacturer', 'model', 'serial_no', 'protocol', 'notes', 'is_active']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('lab.qc');
        $data = $this->validate([
            'form.name' => 'required|string|max:100',
            'form.manufacturer' => 'nullable|string|max:100',
            'form.model' => 'nullable|string|max:100',
            'form.serial_no' => 'nullable|string|max:100',
            'form.protocol' => 'required|in:hl7,astm,csv,api',
            'form.notes' => 'nullable|string|max:500',
            'form.is_active' => 'boolean',
        ])['form'];

        if ($this->editingId) {
            LabDevice::findOrFail($this->editingId)->update($data);
        } else {
            $token = Str::random(40);
            LabDevice::create($data + ['api_token' => $token]);
            $this->revealedToken = $token;
        }
        $this->showForm = false;
        $this->toast('Device saved.');
    }

    public function regenerate(int $id): void
    {
        $this->authorize('lab.qc');
        $token = Str::random(40);
        LabDevice::findOrFail($id)->update(['api_token' => $token]);
        $this->revealedToken = $token;
        $this->toast('New token generated – update the middleware / analyzer config.', 'warning');
    }

    public function with(): array
    {
        return ['devices' => LabDevice::orderBy('name')->get(), 'endpoint' => url('api/lab-devices/results')];
    }
}; ?>

<div>
    <x-page-header title="Lab Devices" subtitle="Analyzer integration" :breadcrumbs="['Lab Orders' => route('tenant.lab.orders')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>Register device</button>
    </x-page-header>

    @if ($revealedToken)
        <div class="alert alert-warning">
            <strong>Device token (shown once):</strong> <code class="user-select-all">{{ $revealedToken }}</code>
            <button class="btn-close float-end" wire:click="$set('revealedToken', null)"></button>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-0">
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead class="table-light"><tr><th>Device</th><th>Protocol</th><th>Serial</th><th>Last seen</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($devices as $d)
                                <tr>
                                    <td class="fw-semibold">{{ $d->name }}<div class="fs-12 text-muted">{{ $d->manufacturer }} {{ $d->model }}</div></td>
                                    <td>{{ strtoupper($d->protocol) }}</td><td>{{ $d->serial_no }}</td><td class="fs-12">{{ $d->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                                    <td><x-status :value="$d->is_active ? 'active' : 'inactive'" /></td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $d->id }})"><i class="ri-edit-line"></i></button>
                                        <button class="btn btn-sm btn-light-warning icon-btn-sm" title="Regenerate token" x-on:click="$confirm('Regenerate the token? The current one stops working.', () => $wire.regenerate({{ $d->id }}), { color: 'warning' })"><i class="ri-key-2-line"></i></button>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="6" message="No devices registered." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-plug-line me-1"></i>Integration (device-ready)</h6></div>
                <div class="card-body fs-13">
                    <p>Analyzers or an HL7/ASTM middleware post results keyed by the sample barcode. Parameter codes map to the codes in the test catalog.</p>
                    <p class="mb-1"><strong>Endpoint</strong></p>
                    <code class="d-block bg-body-tertiary p-2 rounded mb-3">POST {{ $endpoint }}</code>
                    <p class="mb-1"><strong>Headers</strong></p>
                    <code class="d-block bg-body-tertiary p-2 rounded mb-3">Authorization: Bearer &lt;device token&gt;<br>Accept: application/json</code>
                    <p class="mb-1"><strong>Body</strong></p>
<pre class="bg-body-tertiary p-2 rounded mb-0">{
  "barcode": "{{ hospital()->code }}00000012",
  "results": { "HGB": 13.4, "WBC": 7.1, "PLT": 250 }
}</pre>
                    <p class="mt-3 mb-0 text-muted">Results are saved as “completed” and still require approval by a pathologist before the report is released.</p>
                </div>
            </div>
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit device' : 'Register device'">
        <x-form.input label="Name" model="form.name" required />
        <div class="row">
            <x-form.input class="col-md-6" label="Manufacturer" model="form.manufacturer" />
            <x-form.input class="col-md-6" label="Model" model="form.model" />
            <x-form.input class="col-md-6" label="Serial no." model="form.serial_no" />
            <x-form.select class="col-md-6" label="Protocol" model="form.protocol" :options="['api' => 'REST API (JSON)', 'hl7' => 'HL7 via middleware', 'astm' => 'ASTM via middleware', 'csv' => 'CSV import']" :placeholder="false" />
        </div>
        <x-form.textarea label="Notes" model="form.notes" rows="2" />
        <x-form.switch label="Active" model="form.is_active" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
