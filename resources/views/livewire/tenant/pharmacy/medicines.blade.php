<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineCategory;
use App\Models\Supplier;
use App\Services\PharmacyService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Medicines')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'sale_price', 'created_at'];

    #[Url]
    public string $category = '';

    #[Url]
    public string $stockFilter = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public bool $showBatch = false;

    public array $batch = [];

    public string $newCategory = '';

    public function create(): void
    {
        $this->authorize('pharmacy.inventory');
        $this->editingId = null;
        $this->form = ['name' => '', 'generic_name' => '', 'medicine_category_id' => '', 'manufacturer' => '', 'form' => 'tablet', 'strength' => '', 'unit' => 'strip',
            'barcode' => '', 'rack_location' => '', 'reorder_level' => 10, 'purchase_price' => '', 'sale_price' => '', 'tax_percent' => 0, 'requires_prescription' => false, 'is_active' => true];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('pharmacy.inventory');
        $m = Medicine::findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($m->only(array_keys($this->formDefaults())))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    protected function formDefaults(): array
    {
        return array_flip(['name', 'generic_name', 'medicine_category_id', 'manufacturer', 'form', 'strength', 'unit', 'barcode', 'rack_location', 'reorder_level', 'purchase_price', 'sale_price', 'tax_percent', 'requires_prescription', 'is_active']);
    }

    public function save(): void
    {
        $this->authorize('pharmacy.inventory');
        $data = $this->validate([
            'form.name' => 'required|string|max:150',
            'form.generic_name' => 'nullable|string|max:150',
            'form.medicine_category_id' => ['nullable', tenant_exists('medicine_categories')],
            'form.manufacturer' => 'nullable|string|max:100',
            'form.form' => 'required|in:'.implode(',', Medicine::FORMS),
            'form.strength' => 'nullable|string|max:50',
            'form.unit' => 'required|string|max:30',
            'form.barcode' => ['nullable', 'string', 'max:64', tenant_unique('medicines', 'barcode', $this->editingId)],
            'form.rack_location' => 'nullable|string|max:50',
            'form.reorder_level' => 'required|integer|min:0',
            'form.purchase_price' => 'required|integer|min:0',
            'form.sale_price' => 'required|integer|min:0',
            'form.tax_percent' => 'required|numeric|min:0|max:100',
            'form.requires_prescription' => 'boolean',
            'form.is_active' => 'boolean',
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $this->editingId ? Medicine::findOrFail($this->editingId)->update($data) : Medicine::create($data);
        $this->showForm = false;
        $this->toast('Medicine saved.');
    }

    public function addCategory(): void
    {
        $this->authorize('pharmacy.inventory');
        $this->validate(['newCategory' => 'required|string|max:80']);
        $c = MedicineCategory::create(['name' => $this->newCategory]);
        $this->form['medicine_category_id'] = (string) $c->id;
        $this->newCategory = '';
    }

    public function openingStock(int $id): void
    {
        $this->authorize('pharmacy.inventory');
        $m = Medicine::findOrFail($id);
        $this->batch = ['medicine_id' => $id, 'name' => $m->label, 'batch_no' => '', 'expiry_date' => '', 'mfg_date' => '', 'quantity' => '', 'purchase_price' => (string) $m->purchase_price, 'sale_price' => (string) $m->sale_price, 'supplier_id' => ''];
        $this->resetValidation();
        $this->showBatch = true;
    }

    public function saveBatch(PharmacyService $pharmacy): void
    {
        $this->authorize('pharmacy.inventory');
        $this->validate([
            'batch.batch_no' => 'required|string|max:50',
            'batch.expiry_date' => 'required|date|after:today',
            'batch.mfg_date' => 'nullable|date|before_or_equal:today',
            'batch.quantity' => 'required|integer|min:1',
            'batch.purchase_price' => 'required|integer|min:0',
            'batch.sale_price' => 'required|integer|min:0',
            'batch.supplier_id' => ['nullable', tenant_exists('suppliers')],
        ]);
        $b = MedicineBatch::create([
            'medicine_id' => $this->batch['medicine_id'], 'supplier_id' => $this->batch['supplier_id'] ?: null, 'batch_no' => $this->batch['batch_no'],
            'mfg_date' => $this->batch['mfg_date'] ?: null, 'expiry_date' => $this->batch['expiry_date'], 'quantity_received' => $this->batch['quantity'],
            'quantity_available' => 0, 'purchase_price' => $this->batch['purchase_price'], 'sale_price' => $this->batch['sale_price'],
        ]);
        $pharmacy->move($b, (int) $this->batch['quantity'], 'purchase', null, 'Opening / direct stock entry');
        $this->showBatch = false;
        $this->toast('Stock added.');
    }

    public function with(): array
    {
        $query = Medicine::with('category')->withStock()
            ->search($this->search)
            ->when($this->category, fn ($q) => $q->where('medicine_category_id', $this->category));

        if ($this->stockFilter === 'low') {
            $ids = Medicine::withStock()->get()->filter(fn ($m) => (int) $m->stock <= $m->reorder_level)->pluck('id');
            $query->whereIn('id', $ids);
        } elseif ($this->stockFilter === 'out') {
            $ids = Medicine::withStock()->get()->filter(fn ($m) => (int) $m->stock <= 0)->pluck('id');
            $query->whereIn('id', $ids);
        }

        return [
            'medicines' => $this->applySort($query)->paginate($this->perPage),
            'categories' => MedicineCategory::orderBy('name')->pluck('name', 'id'),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'forms' => array_combine(Medicine::FORMS, array_map('ucfirst', Medicine::FORMS)),
        ];
    }
}; ?>

<div>
    <x-page-header title="Medicines" subtitle="Formulary & pricing" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]">
        @can('pharmacy.inventory')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New medicine</button>@endcan
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Name, generic or barcode...">
            <select class="form-select w-auto" wire:model.live="category"><option value="">All categories</option>@foreach ($categories as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="stockFilter"><option value="">All stock</option><option value="low">Low stock</option><option value="out">Out of stock</option></select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><x-th field="name" :sort="$sortField" :dir="$sortDirection">Medicine</x-th><th>Category</th><th>Form</th><th>Rack</th><th class="text-end">Cost</th><x-th field="sale_price" :sort="$sortField" :dir="$sortDirection" class="text-end">Price</x-th><th class="text-end">Stock</th><th></th></tr></thead>
                <tbody>
                    @forelse ($medicines as $m)
                        <tr wire:key="med-{{ $m->id }}" class="{{ $m->is_active ? '' : 'opacity-50' }}">
                            <td><strong>{{ $m->label }}</strong> @if ($m->requires_prescription)<span class="badge bg-warning-subtle text-warning">Rx</span>@endif<div class="fs-12 text-muted">{{ collect([$m->generic_name, $m->manufacturer, $m->barcode])->filter()->implode(' · ') }}</div></td>
                            <td>{{ $m->category?->name ?? '—' }}</td>
                            <td>{{ ucfirst($m->form) }} / {{ $m->unit }}</td>
                            <td>{{ $m->rack_location ?: '—' }}</td>
                            <td class="text-end">{{ money($m->purchase_price) }}</td>
                            <td class="text-end">{{ money($m->sale_price) }}</td>
                            <td class="text-end"><span class="badge {{ (int) $m->stock <= 0 ? 'bg-danger' : ((int) $m->stock <= $m->reorder_level ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">{{ (int) $m->stock }}</span></td>
                            <td class="text-end text-nowrap">
                                @can('pharmacy.inventory')
                                    <button class="btn btn-sm btn-light-success icon-btn-sm" title="Add stock / batch" wire:click="openingStock({{ $m->id }})"><i class="ri-add-box-line"></i></button>
                                    <button class="btn btn-sm btn-light-primary icon-btn-sm" title="Edit" wire:click="edit({{ $m->id }})"><i class="ri-edit-line"></i></button>
                                @endcan
                                <a href="{{ route('tenant.pharmacy.stock', ['medicine' => $m->id]) }}" wire:navigate class="btn btn-sm btn-light icon-btn-sm" title="Batches"><i class="ri-stack-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No medicines." icon="ri-capsule-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $medicines->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit medicine' : 'New medicine'" size="lg">
        <div class="row">
            <x-form.input class="col-md-6" label="Brand name" model="form.name" required />
            <x-form.input class="col-md-6" label="Generic name" model="form.generic_name" />
            <div class="col-md-6 mb-3">
                <x-form.select class="mb-1" label="Category" model="form.medicine_category_id" :options="$categories" />
                <div class="input-group input-group-sm"><input type="text" class="form-control" placeholder="New category" wire:model="newCategory"><button class="btn btn-light" type="button" wire:click="addCategory">Add</button></div>
            </div>
            <x-form.input class="col-md-6" label="Manufacturer" model="form.manufacturer" />
            <x-form.select class="col-md-4" label="Form" model="form.form" :options="$forms" :placeholder="false" />
            <x-form.input class="col-md-4" label="Strength" model="form.strength" placeholder="500mg" />
            <x-form.input class="col-md-4" label="Selling unit" model="form.unit" placeholder="strip / bottle" />
            <x-form.input class="col-md-4" label="Barcode" model="form.barcode" />
            <x-form.input class="col-md-4" label="Rack location" model="form.rack_location" />
            <x-form.input class="col-md-4" label="Re-order level" model="form.reorder_level" type="number" />
            <x-form.money class="col-md-4" label="Purchase price" model="form.purchase_price" required />
            <x-form.money class="col-md-4" label="Sale price (MRP)" model="form.sale_price" required />
            <x-form.input class="col-md-4" label="Tax %" model="form.tax_percent" type="number" step="0.01" />
            <x-form.switch class="col-md-6" label="Prescription required" model="form.requires_prescription" />
            <x-form.switch class="col-md-6" label="Active" model="form.is_active" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showBatch" :title="'Add stock · '.($batch['name'] ?? '')">
        <div class="row">
            <x-form.input class="col-md-6" label="Batch no." model="batch.batch_no" required />
            <x-form.input class="col-md-6" label="Quantity" model="batch.quantity" type="number" required />
            <x-form.input class="col-md-6" label="Mfg date" model="batch.mfg_date" type="date" />
            <x-form.input class="col-md-6" label="Expiry date" model="batch.expiry_date" type="date" required />
            <x-form.money class="col-md-6" label="Purchase price" model="batch.purchase_price" />
            <x-form.money class="col-md-6" label="Sale price" model="batch.sale_price" />
            <x-form.select class="col-12" label="Supplier" model="batch.supplier_id" :options="$suppliers" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveBatch">Add stock</button></x-slot:footer>
    </x-modal>
</div>
