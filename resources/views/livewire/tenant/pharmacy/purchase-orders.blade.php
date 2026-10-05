<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Medicine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Purchase Orders')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'order_date';

    protected array $sortable = ['order_date', 'total'];

    #[Url]
    public string $status = '';

    public bool $showForm = false;

    public array $form = [];

    public array $lines = [];

    public function mount(): void
    {
        if (request()->boolean('reorder')) {
            $this->create(true);
        }
    }

    public function create(bool $reorder = false): void
    {
        $this->authorize('pharmacy.purchase');
        $this->form = ['supplier_id' => (string) Supplier::where('is_active', true)->value('id'), 'order_date' => today()->toDateString(), 'expected_date' => today()->addDays(3)->toDateString(), 'notes' => ''];
        $this->lines = [];
        if ($reorder) {
            foreach (Medicine::withStock()->where('is_active', true)->get()->filter(fn ($m) => (int) $m->stock <= $m->reorder_level) as $m) {
                $this->lines[] = ['medicine_id' => (string) $m->id, 'quantity' => max($m->reorder_level * 3 - (int) $m->stock, 10), 'unit_price' => (string) $m->purchase_price, 'tax_percent' => 0];
            }
        }
        if (! $this->lines) {
            $this->addLine();
        }
        $this->resetValidation();
        $this->showForm = true;
    }

    public function addLine(): void
    {
        $this->lines[] = ['medicine_id' => '', 'quantity' => 10, 'unit_price' => '', 'tax_percent' => 0];
    }

    public function removeLine(int $i): void
    {
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function updatedLines($value, $key): void
    {
        [$i, $field] = explode('.', $key);
        if ($field === 'medicine_id' && $value && ! $this->lines[$i]['unit_price']) {
            $this->lines[$i]['unit_price'] = (string) Medicine::find($value)?->purchase_price;
        }
    }

    public function save(string $status = 'draft')
    {
        $this->authorize('pharmacy.purchase');
        $this->validate([
            'form.supplier_id' => ['required', tenant_exists('suppliers')],
            'form.order_date' => 'required|date',
            'form.expected_date' => 'nullable|date|after_or_equal:form.order_date',
            'form.notes' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.medicine_id' => ['required', tenant_exists('medicines')],
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.unit_price' => 'required|integer|min:0',
            'lines.*.tax_percent' => 'nullable|numeric|min:0|max:100',
        ], [], ['lines.*.medicine_id' => 'medicine']);

        $po = DB::transaction(function () use ($status) {
            $po = PurchaseOrder::create($this->form + ['po_no' => Sequence::code('po', 'PO'), 'status' => $status === 'ordered' ? 'ordered' : 'draft', 'created_by' => auth()->id()]);
            $subtotal = 0;
            $tax = 0;
            foreach ($this->lines as $l) {
                $line = $l['quantity'] * rupees($l['unit_price']);
                $lineTax = rupees($line * (float) $l['tax_percent'] / 100);
                $po->items()->create(['medicine_id' => $l['medicine_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'tax_percent' => $l['tax_percent'] ?: 0, 'total' => $line + $lineTax]);
                $subtotal += $line;
                $tax += $lineTax;
            }
            $po->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);

            return $po;
        });

        session()->flash('success', "Purchase order {$po->po_no} saved.");

        return $this->redirect(route('tenant.pharmacy.purchase-order', $po), navigate: true);
    }

    public function with(): array
    {
        $query = PurchaseOrder::with('supplier')->withCount('items')
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('po_no', 'like', "%{$this->search}%")->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$this->search}%"))));

        return [
            'orders' => $this->applySort($query)->paginate($this->perPage),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'medicines' => Medicine::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->label])->all(),
            'poTotal' => collect($this->lines)->sum(fn ($l) => (float) ($l['quantity'] ?: 0) * (float) ($l['unit_price'] ?: 0) * (1 + (float) ($l['tax_percent'] ?: 0) / 100)),
        ];
    }
}; ?>

<div>
    <x-page-header title="Purchase Orders" subtitle="Procurement" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New purchase order</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="PO # or supplier...">
            <select class="form-select w-auto" wire:model.live="status"><option value="">All</option>@foreach (['draft', 'ordered', 'partially_received', 'received', 'cancelled'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>PO</th><x-th field="order_date" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Supplier</th><th>Items</th><th>Expected</th><x-th field="total" :sort="$sortField" :dir="$sortDirection">Total</x-th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr wire:key="po-{{ $o->id }}">
                            <td class="fw-semibold">{{ $o->po_no }}</td><td>{{ fmt_date($o->order_date) }}</td><td>{{ $o->supplier->name }}</td><td>{{ $o->items_count }}</td><td>{{ fmt_date($o->expected_date) }}</td><td>{{ money($o->total) }}</td><td><x-status :value="$o->status" /></td>
                            <td class="text-end"><a href="{{ route('tenant.pharmacy.purchase-order', $o) }}" wire:navigate class="btn btn-sm btn-light-primary">Open</a></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No purchase orders." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $orders->links() }}</div>
    </div>

    <x-modal wire:model="showForm" title="New purchase order" size="xl">
        <div class="row">
            <x-form.select class="col-md-5" label="Supplier" model="form.supplier_id" :options="$suppliers" required />
            <x-form.input class="col-md-3" label="Order date" model="form.order_date" type="date" />
            <x-form.input class="col-md-4" label="Expected delivery" model="form.expected_date" type="date" />
        </div>
        <table class="table table-sm align-middle">
            <thead><tr><th style="width: 45%;">Medicine</th><th>Qty</th><th>Unit cost</th><th>Tax %</th><th class="text-end">Line total</th><th></th></tr></thead>
            <tbody>
                @foreach ($lines as $i => $l)
                    <tr wire:key="pol-{{ $i }}">
                        <td><x-form.search-select class="mb-0" model="lines.{{ $i }}.medicine_id" :options="$medicines" live /></td>
                        <td><input type="number" min="1" class="form-control form-control-sm @error('lines.'.$i.'.quantity') is-invalid @enderror" wire:model.live="lines.{{ $i }}.quantity"></td>
                        <td><input type="number" step="1" min="0" inputmode="numeric" class="form-control form-control-sm @error('lines.'.$i.'.unit_price') is-invalid @enderror" wire:model.live="lines.{{ $i }}.unit_price"></td>
                        <td><input type="number" step="0.01" class="form-control form-control-sm @error('lines.'.$i.'.tax_percent') is-invalid @enderror" wire:model.live="lines.{{ $i }}.tax_percent"></td>
                        <td class="text-end">{{ money((float) ($l['quantity'] ?: 0) * (float) ($l['unit_price'] ?: 0) * (1 + (float) ($l['tax_percent'] ?: 0) / 100)) }}</td>
                        <td><button class="btn btn-sm btn-link text-danger" wire:click="removeLine({{ $i }})" title="Remove line"><i class="ri-close-line"></i></button></td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="4"><button class="btn btn-sm btn-light-primary" wire:click="addLine"><i class="ri-add-line"></i> Add line</button></td><th class="text-end">{{ money($poTotal) }}</th><td></td></tr></tfoot>
        </table>
        @error('lines')<div class="text-danger">{{ $message }}</div>@enderror
        @php $lineErrors = collect($errors->getMessages())->filter(fn ($m, $k) => preg_match('/^lines\.\d+\.(quantity|unit_price|tax_percent)$/', $k))->flatten()->unique(); @endphp
        @if ($lineErrors->isNotEmpty())<div class="text-danger fs-13 mb-2">{{ $lineErrors->implode(' ') }}</div>@endif
        <x-form.textarea label="Notes" model="form.notes" rows="2" />
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-light-primary" wire:click="save('draft')">Save draft</button>
            <button class="btn btn-primary" wire:click="save('ordered')">Place order</button>
        </x-slot:footer>
    </x-modal>
</div>
