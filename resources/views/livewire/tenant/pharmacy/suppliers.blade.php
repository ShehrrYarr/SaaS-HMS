<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Supplier;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Suppliers')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'tax_no' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->editingId = $id;
        $this->form = Supplier::findOrFail($id)->only(['name', 'contact_person', 'phone', 'email', 'address', 'tax_no', 'is_active']);
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('pharmacy.purchase');
        $data = $this->validate([
            'form.name' => 'required|string|max:150',
            'form.contact_person' => 'nullable|string|max:100',
            'form.phone' => 'nullable|string|max:30',
            'form.email' => 'nullable|email',
            'form.address' => 'nullable|string|max:255',
            'form.tax_no' => 'nullable|string|max:50',
            'form.is_active' => 'boolean',
        ])['form'];
        $this->editingId ? Supplier::findOrFail($this->editingId)->update($data) : Supplier::create($data);
        $this->showForm = false;
        $this->toast('Supplier saved.');
    }

    public function with(): array
    {
        $query = Supplier::withCount('purchaseOrders')->withSum('purchaseOrders as purchased', 'total')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('contact_person', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")));

        return ['suppliers' => $this->applySort($query)->paginate($this->perPage)];
    }
}; ?>

<div>
    <x-page-header title="Suppliers" subtitle="Vendors" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New supplier</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Search suppliers..." />
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><x-th field="name" :sort="$sortField" :dir="$sortDirection">Supplier</x-th><th>Contact</th><th>Phone</th><th>Email</th><th>Tax no.</th><th class="text-end">POs</th><th class="text-end">Purchased</th><th></th></tr></thead>
                <tbody>
                    @forelse ($suppliers as $s)
                        <tr wire:key="sup-{{ $s->id }}" class="{{ $s->is_active ? '' : 'opacity-50' }}">
                            <td class="fw-semibold">{{ $s->name }}</td><td>{{ $s->contact_person }}</td><td>{{ $s->phone }}</td><td>{{ $s->email }}</td><td>{{ $s->tax_no }}</td>
                            <td class="text-end">{{ $s->purchase_orders_count }}</td><td class="text-end">{{ money($s->purchased ?? 0) }}</td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $s->id }})"><i class="ri-edit-line"></i></button></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No suppliers." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $suppliers->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit supplier' : 'New supplier'">
        <x-form.input label="Company name" model="form.name" required />
        <div class="row">
            <x-form.input class="col-md-6" label="Contact person" model="form.contact_person" />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-md-6" label="Email" model="form.email" type="email" />
            <x-form.input class="col-md-6" label="Tax / registration no." model="form.tax_no" />
        </div>
        <x-form.input label="Address" model="form.address" />
        <x-form.switch label="Active" model="form.is_active" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
