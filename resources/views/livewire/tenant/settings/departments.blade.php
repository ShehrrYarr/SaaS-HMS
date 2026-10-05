<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Department;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Departments')] class extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'code' => '', 'type' => 'clinical', 'description' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(Department::findOrFail($id)->only(['name', 'code', 'type', 'description', 'is_active']))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('departments.manage');
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100', tenant_unique('departments', 'name', $this->editingId)],
            'form.code' => ['nullable', 'string', 'max:20', tenant_unique('departments', 'code', $this->editingId)],
            'form.type' => 'required|in:clinical,diagnostic,support,administrative',
            'form.description' => 'nullable|string|max:500',
            'form.is_active' => 'boolean',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $this->editingId ? Department::findOrFail($this->editingId)->update($data) : Department::create($data);
        $this->showForm = false;
        $this->toast('Department saved.');
    }

    public function with(): array
    {
        return ['departments' => Department::withCount('staff')->orderBy('type')->orderBy('name')->get()];
    }
}; ?>

<div>
    <x-page-header title="Departments" subtitle="Settings">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New department</button>
    </x-page-header>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Department</th><th>Code</th><th>Type</th><th>Staff</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($departments as $d)
                        <tr><td class="fw-semibold">{{ $d->name }}<div class="fs-12 text-muted">{{ $d->description }}</div></td><td>{{ $d->code }}</td><td>{{ label($d->type) }}</td><td>{{ $d->staff_count }}</td>
                            <td><x-status :value="$d->is_active ? 'active' : 'inactive'" /></td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $d->id }})"><i class="ri-edit-line"></i></button></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <x-modal wire:model="showForm" :title="$editingId ? 'Edit department' : 'New department'">
        <div class="row">
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.input class="col-md-4" label="Code" model="form.code" />
            <x-form.select class="col-12" label="Type" model="form.type" :options="['clinical' => 'Clinical', 'diagnostic' => 'Diagnostic', 'support' => 'Support', 'administrative' => 'Administrative']" :placeholder="false" />
            <x-form.textarea class="col-12" label="Description" model="form.description" rows="2" />
            <x-form.switch class="col-12" label="Active" model="form.is_active" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
