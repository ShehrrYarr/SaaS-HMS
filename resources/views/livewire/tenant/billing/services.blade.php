<?php

use App\Livewire\Concerns\WithTable;
use App\Models\ServiceCharge;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Service Charges')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'price', 'category'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['code' => '', 'name' => '', 'category' => 'procedure', 'price' => '', 'tax_percent' => (string) hospital()->tax_rate, 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = collect(ServiceCharge::findOrFail($id)->only(['code', 'name', 'category', 'price', 'tax_percent', 'is_active']))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('billing.create');
        $data = $this->validate([
            'form.code' => 'nullable|string|max:20',
            'form.name' => 'required|string|max:150',
            'form.category' => 'required|string|max:30',
            'form.price' => 'required|numeric|min:0',
            'form.tax_percent' => 'required|numeric|min:0|max:100',
            'form.is_active' => 'boolean',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $this->editingId ? ServiceCharge::findOrFail($this->editingId)->update($data) : ServiceCharge::create($data);
        $this->showForm = false;
        $this->toast('Service saved.');
    }

    public function with(): array
    {
        $query = ServiceCharge::when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")));

        return ['services' => $this->applySort($query)->paginate($this->perPage), 'categories' => ['registration' => 'Registration', 'consultation' => 'Consultation', 'procedure' => 'Procedure', 'nursing' => 'Nursing', 'room' => 'Room', 'consumable' => 'Consumable', 'transport' => 'Transport', 'other' => 'Other']];
    }
}; ?>

<div>
    <x-page-header title="Service Charges" subtitle="Price list" :breadcrumbs="['Billing' => route('tenant.billing.invoices')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New service</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Service name or code..." />
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Code</th><x-th field="name" :sort="$sortField" :dir="$sortDirection">Service</x-th><x-th field="category" :sort="$sortField" :dir="$sortDirection">Category</x-th><x-th field="price" :sort="$sortField" :dir="$sortDirection" class="text-end">Price</x-th><th class="text-end">Tax %</th><th></th></tr></thead>
                <tbody>
                    @forelse ($services as $s)
                        <tr class="{{ $s->is_active ? '' : 'opacity-50' }}"><td>{{ $s->code }}</td><td>{{ $s->name }}</td><td>{{ label($s->category) }}</td><td class="text-end">{{ money($s->price) }}</td><td class="text-end">{{ (float) $s->tax_percent }}</td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $s->id }})"><i class="ri-edit-line"></i></button></td></tr>
                    @empty
                        <x-empty-row :colspan="6" message="No services." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $services->links() }}</div>
    </div>
    <x-modal wire:model="showForm" :title="$editingId ? 'Edit service' : 'New service'">
        <div class="row">
            <x-form.input class="col-md-4" label="Code" model="form.code" />
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.select class="col-md-6" label="Category" model="form.category" :options="$categories" :placeholder="false" />
            <x-form.input class="col-md-3" label="Price" model="form.price" type="number" step="0.01" required />
            <x-form.input class="col-md-3" label="Tax %" model="form.tax_percent" type="number" step="0.01" />
            <x-form.switch class="col-12" label="Active" model="form.is_active" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
