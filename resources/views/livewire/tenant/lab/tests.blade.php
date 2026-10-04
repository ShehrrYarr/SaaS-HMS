<?php

use App\Livewire\Concerns\WithTable;
use App\Models\LabTest;
use App\Models\LabTestCategory;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Lab Test Catalog')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'price', 'code'];

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public array $params = [];

    public string $newCategory = '';

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['lab_test_category_id' => '', 'code' => '', 'name' => '', 'sample_type' => 'blood', 'container' => '', 'price' => '', 'turnaround_hours' => 24, 'method' => '', 'description' => '', 'is_active' => true];
        $this->params = [];
        $this->addParam();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $t = LabTest::with('parameters')->findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($t->only(['lab_test_category_id', 'code', 'name', 'sample_type', 'container', 'price', 'turnaround_hours', 'method', 'description', 'is_active']))->map(fn ($v) => is_bool($v) ? $v : (string) $v)->all();
        $this->params = $t->parameters->sortBy('sort_order')->map(fn ($p) => [
            'id' => $p->id, 'code' => (string) $p->code, 'name' => $p->name, 'unit' => (string) $p->unit, 'result_type' => $p->result_type, 'options' => implode(', ', $p->options ?? []),
            'ref_min' => (string) $p->ref_min, 'ref_max' => (string) $p->ref_max, 'male_min' => (string) $p->male_min, 'male_max' => (string) $p->male_max,
            'female_min' => (string) $p->female_min, 'female_max' => (string) $p->female_max, 'critical_low' => (string) $p->critical_low, 'critical_high' => (string) $p->critical_high, 'ref_text' => (string) $p->ref_text,
        ])->values()->all();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function addParam(): void
    {
        $this->params[] = ['id' => null, 'code' => '', 'name' => '', 'unit' => '', 'result_type' => 'numeric', 'options' => '', 'ref_min' => '', 'ref_max' => '', 'male_min' => '', 'male_max' => '', 'female_min' => '', 'female_max' => '', 'critical_low' => '', 'critical_high' => '', 'ref_text' => ''];
    }

    public function removeParam(int $i): void
    {
        unset($this->params[$i]);
        $this->params = array_values($this->params);
    }

    public function addCategory(): void
    {
        $this->validate(['newCategory' => 'required|string|max:80']);
        $this->form['lab_test_category_id'] = (string) LabTestCategory::create(['name' => $this->newCategory])->id;
        $this->newCategory = '';
    }

    public function save(): void
    {
        $this->authorize('lab.manage_tests');
        $data = $this->validate([
            'form.lab_test_category_id' => ['nullable', tenant_exists('lab_test_categories')],
            'form.code' => 'required|string|max:20',
            'form.name' => 'required|string|max:150',
            'form.sample_type' => 'required|string|max:30',
            'form.container' => 'nullable|string|max:50',
            'form.price' => 'required|numeric|min:0',
            'form.turnaround_hours' => 'required|integer|min:1|max:2000',
            'form.method' => 'nullable|string|max:100',
            'form.description' => 'nullable|string|max:1000',
            'form.is_active' => 'boolean',
            'params' => 'required|array|min:1',
            'params.*.name' => 'required|string|max:120',
            'params.*.code' => 'nullable|string|max:30',
            'params.*.unit' => 'nullable|string|max:30',
            'params.*.result_type' => 'required|in:numeric,text,option',
            'params.*.ref_min' => 'nullable|numeric', 'params.*.ref_max' => 'nullable|numeric',
            'params.*.male_min' => 'nullable|numeric', 'params.*.male_max' => 'nullable|numeric',
            'params.*.female_min' => 'nullable|numeric', 'params.*.female_max' => 'nullable|numeric',
            'params.*.critical_low' => 'nullable|numeric', 'params.*.critical_high' => 'nullable|numeric',
            'params.*.ref_text' => 'nullable|string|max:100',
        ], [], ['params.*.name' => 'parameter name'])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        DB::transaction(function () use ($data) {
            $test = $this->editingId ? tap(LabTest::findOrFail($this->editingId))->update($data) : LabTest::create($data);
            $keep = [];
            foreach ($this->params as $i => $p) {
                $attrs = collect($p)->except(['id', 'options'])->map(fn ($v) => $v === '' ? null : $v)->all() + [
                    'options' => $p['result_type'] === 'option' ? array_values(array_filter(array_map('trim', explode(',', $p['options'])))) : null,
                    'sort_order' => $i,
                ];
                $param = $p['id'] ? $test->parameters()->findOrFail($p['id']) : null;
                $param ? $param->update($attrs) : $param = $test->parameters()->create($attrs);
                $keep[] = $param->id;
            }
            // Remove dropped parameters unless results already reference them.
            $test->parameters()->whereNotIn('id', $keep)->get()
                ->each(fn ($p) => \App\Models\LabResult::where('lab_test_parameter_id', $p->id)->exists() ? null : $p->delete());
        });

        $this->showForm = false;
        $this->toast('Test saved.');
    }

    public function with(): array
    {
        $query = LabTest::with('category')->withCount('parameters')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")));

        return [
            'tests' => $this->applySort($query)->paginate($this->perPage),
            'categories' => LabTestCategory::orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Test Catalog" subtitle="Tests, pricing & reference ranges" :breadcrumbs="['Lab Orders' => route('tenant.lab.orders')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New test</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Test name or code..." />
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><x-th field="code" :sort="$sortField" :dir="$sortDirection">Code</x-th><x-th field="name" :sort="$sortField" :dir="$sortDirection">Test</x-th><th>Category</th><th>Sample</th><th>Parameters</th><th>TAT</th><x-th field="price" :sort="$sortField" :dir="$sortDirection" class="text-end">Price</x-th><th></th></tr></thead>
                <tbody>
                    @forelse ($tests as $t)
                        <tr wire:key="lt-{{ $t->id }}" class="{{ $t->is_active ? '' : 'opacity-50' }}">
                            <td class="fw-semibold">{{ $t->code }}</td><td>{{ $t->name }}</td><td>{{ $t->category?->name ?? '—' }}</td><td>{{ label($t->sample_type) }} <small class="text-muted">{{ $t->container }}</small></td>
                            <td>{{ $t->parameters_count }}</td><td>{{ $t->turnaround_hours }}h</td><td class="text-end">{{ money($t->price) }}</td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $t->id }})"><i class="ri-edit-line"></i></button></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No tests." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $tests->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit test' : 'New test'" size="xl">
        <div class="row">
            <x-form.input class="col-md-2" label="Code" model="form.code" required />
            <x-form.input class="col-md-5" label="Test name" model="form.name" required />
            <div class="col-md-5 mb-3">
                <x-form.select class="mb-1" label="Category" model="form.lab_test_category_id" :options="$categories" />
                <div class="input-group input-group-sm"><input type="text" class="form-control" placeholder="New category" wire:model="newCategory"><button type="button" class="btn btn-light" wire:click="addCategory">Add</button></div>
            </div>
            <x-form.select class="col-md-3" label="Sample" model="form.sample_type" :options="['blood' => 'Blood', 'serum' => 'Serum', 'plasma' => 'Plasma', 'urine' => 'Urine', 'stool' => 'Stool', 'swab' => 'Swab', 'sputum' => 'Sputum', 'csf' => 'CSF', 'tissue' => 'Tissue', 'other' => 'Other']" :placeholder="false" />
            <x-form.input class="col-md-3" label="Container" model="form.container" />
            <x-form.input class="col-md-2" label="Price" model="form.price" type="number" step="0.01" required />
            <x-form.input class="col-md-2" label="TAT (hours)" model="form.turnaround_hours" type="number" />
            <x-form.input class="col-md-2" label="Method" model="form.method" />
        </div>
        <h6 class="mt-2">Parameters &amp; reference ranges</h6>
        <div class="table-responsive">
            <table class="table table-sm align-middle fs-13">
                <thead><tr><th>Code</th><th>Name*</th><th>Unit</th><th>Type</th><th>Ref min–max</th><th>Male</th><th>Female</th><th>Critical L/H</th><th>Ref text / options</th><th></th></tr></thead>
                <tbody>
                    @foreach ($params as $i => $p)
                        <tr wire:key="pp-{{ $i }}">
                            <td><input class="form-control form-control-sm" style="width:70px" wire:model="params.{{ $i }}.code"></td>
                            <td><input class="form-control form-control-sm @error('params.'.$i.'.name') is-invalid @enderror" wire:model="params.{{ $i }}.name"></td>
                            <td><input class="form-control form-control-sm" style="width:80px" wire:model="params.{{ $i }}.unit"></td>
                            <td><select class="form-select form-select-sm" wire:model.live="params.{{ $i }}.result_type"><option value="numeric">Numeric</option><option value="text">Text</option><option value="option">Options</option></select></td>
                            <td><div class="d-flex gap-1"><input class="form-control form-control-sm" style="width:65px" wire:model="params.{{ $i }}.ref_min"><input class="form-control form-control-sm" style="width:65px" wire:model="params.{{ $i }}.ref_max"></div></td>
                            <td><div class="d-flex gap-1"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.male_min"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.male_max"></div></td>
                            <td><div class="d-flex gap-1"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.female_min"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.female_max"></div></td>
                            <td><div class="d-flex gap-1"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.critical_low"><input class="form-control form-control-sm" style="width:60px" wire:model="params.{{ $i }}.critical_high"></div></td>
                            <td>
                                @if ($p['result_type'] === 'option')<input class="form-control form-control-sm" placeholder="Negative, Positive" wire:model="params.{{ $i }}.options">@endif
                                <input class="form-control form-control-sm mt-1" placeholder="Normal text" wire:model="params.{{ $i }}.ref_text">
                            </td>
                            <td><button class="btn btn-sm btn-link text-danger" wire:click="removeParam({{ $i }})"><i class="ri-close-line"></i></button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn btn-sm btn-light-primary" wire:click="addParam"><i class="ri-add-line"></i> Parameter</button>
        @error('params')<div class="text-danger">{{ $message }}</div>@enderror
        <x-form.switch class="mt-3" label="Active" model="form.is_active" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save test</button></x-slot:footer>
    </x-modal>
</div>
