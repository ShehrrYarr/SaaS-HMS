<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Tpa;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Insurance Companies')] class extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->canAny(['insurance.manage', 'settings.manage']), 403);
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'code' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(Tpa::findOrFail($id)->only(['name', 'code', 'contact_person', 'phone', 'email', 'address', 'is_active']))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->canAny(['insurance.manage', 'settings.manage']), 403);
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150', tenant_unique('tpas', 'name', $this->editingId)],
            'form.code' => ['nullable', 'string', 'max:20', tenant_unique('tpas', 'code', $this->editingId)],
            'form.contact_person' => 'nullable|string|max:100',
            'form.phone' => 'nullable|string|max:30',
            'form.email' => 'nullable|email',
            'form.address' => 'nullable|string|max:255',
            'form.is_active' => 'boolean',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $this->editingId ? Tpa::findOrFail($this->editingId)->update($data) : Tpa::create($data);
        $this->showForm = false;
        $this->toast('Saved.');
    }

    public function with(): array
    {
        return ['tpas' => Tpa::withCount(['patients', 'claims'])->orderBy('name')->get()];
    }
}; ?>

<div>
    <x-page-header title="Insurance Companies / TPAs" subtitle="Payers">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New TPA</button>
    </x-page-header>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Code</th><th>Contact</th><th>Phone / email</th><th>Patients</th><th>Claims</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($tpas as $t)
                        <tr><td class="fw-semibold">{{ $t->name }}</td><td>{{ $t->code }}</td><td>{{ $t->contact_person }}</td><td class="fs-12">{{ $t->phone }}<div>{{ $t->email }}</div></td><td>{{ $t->patients_count }}</td><td>{{ $t->claims_count }}</td>
                            <td><x-status :value="$t->is_active ? 'active' : 'inactive'" /></td><td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $t->id }})"><i class="ri-edit-line"></i></button></td></tr>
                    @empty
                        <x-empty-row :colspan="8" message="No insurance companies." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <x-modal wire:model="showForm" :title="$editingId ? 'Edit TPA' : 'New TPA'">
        <div class="row">
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.input class="col-md-4" label="Code" model="form.code" />
            <x-form.input class="col-md-6" label="Contact person" model="form.contact_person" />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-md-6" label="Email" model="form.email" type="email" />
            <x-form.input class="col-md-6" label="Address" model="form.address" />
            <x-form.switch class="col-12" label="Active" model="form.is_active" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
