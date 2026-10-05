<?php

use App\Livewire\Concerns\WithTable;
use App\Models\RadiologyTest;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Imaging Catalog')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'price', 'code'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'modality' => 'xray', 'body_part' => '', 'price' => '', 'preparation' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(RadiologyTest::findOrFail($id)->only(['code', 'name', 'modality', 'body_part', 'price', 'preparation', 'is_active']))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('radiology.manage_tests');
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:20', tenant_unique('radiology_tests', 'code', $this->editingId)],
            'form.name' => ['required', 'string', 'max:150', tenant_unique('radiology_tests', 'name', $this->editingId)],
            'form.modality' => 'required|in:'.implode(',', array_keys(RadiologyTest::MODALITIES)),
            'form.body_part' => 'nullable|string|max:100',
            'form.price' => 'required|integer|min:0',
            'form.preparation' => 'nullable|string|max:1000',
            'form.is_active' => 'boolean',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $this->editingId ? RadiologyTest::findOrFail($this->editingId)->update($data) : RadiologyTest::create($data);
        $this->showForm = false;
        $this->toast('Imaging study saved.');
    }

    public function with(): array
    {
        $query = RadiologyTest::when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")));

        return ['tests' => $this->applySort($query)->paginate($this->perPage), 'modalities' => RadiologyTest::MODALITIES];
    }
}; ?>

<div>
    <x-page-header title="Imaging Catalog" subtitle="Studies & pricing" :breadcrumbs="['Imaging Orders' => route('tenant.radiology.orders')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New study</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Study name or code..." />
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><x-th field="code" :sort="$sortField" :dir="$sortDirection">Code</x-th><x-th field="name" :sort="$sortField" :dir="$sortDirection">Study</x-th><th>Modality</th><th>Body part</th><x-th field="price" :sort="$sortField" :dir="$sortDirection" class="text-end">Price</x-th><th></th></tr></thead>
                <tbody>
                    @forelse ($tests as $t)
                        <tr class="{{ $t->is_active ? '' : 'opacity-50' }}"><td class="fw-semibold">{{ $t->code }}</td><td>{{ $t->name }}</td><td>{{ $modalities[$t->modality] ?? $t->modality }}</td><td>{{ $t->body_part }}</td><td class="text-end">{{ money($t->price) }}</td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $t->id }})"><i class="ri-edit-line"></i></button></td></tr>
                    @empty
                        <x-empty-row :colspan="6" message="No imaging studies." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $tests->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit study' : 'New study'">
        <div class="row">
            <x-form.input class="col-md-4" label="Code" model="form.code" required />
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.select class="col-md-6" label="Modality" model="form.modality" :options="$modalities" :placeholder="false" />
            <x-form.input class="col-md-6" label="Body part" model="form.body_part" />
            <x-form.money class="col-md-6" label="Price" model="form.price" required />
            <x-form.textarea class="col-12" label="Patient preparation" model="form.preparation" rows="2" />
            <x-form.switch class="col-12" label="Active" model="form.is_active" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
