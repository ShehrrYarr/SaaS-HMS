<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\StockMovement;
use App\Services\PharmacyService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stock & Expiry')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'expiry_date';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['expiry_date', 'quantity_available', 'created_at'];

    #[Url]
    public string $view = 'batches';

    #[Url]
    public string $filter = '';

    #[Url]
    public ?string $medicine = null;

    public bool $showAdjust = false;

    public array $adjust = [];

    public function openAdjust(int $batchId): void
    {
        $this->authorize('pharmacy.inventory');
        $b = MedicineBatch::with('medicine')->findOrFail($batchId);
        $this->adjust = ['batch_id' => $b->id, 'label' => $b->medicine->label.' · '.$b->batch_no, 'available' => $b->quantity_available, 'direction' => 'out', 'quantity' => '', 'reason' => 'damaged', 'note' => ''];
        $this->resetValidation();
        $this->showAdjust = true;
    }

    public function saveAdjust(PharmacyService $pharmacy): void
    {
        $this->authorize('pharmacy.inventory');
        $this->validate([
            'adjust.direction' => 'required|in:in,out',
            'adjust.quantity' => 'required|integer|min:1',
            'adjust.reason' => 'required|in:damaged,expired,count_correction,returned_to_supplier,other',
            'adjust.note' => 'nullable|string|max:200',
        ]);
        $batch = MedicineBatch::findOrFail($this->adjust['batch_id']);
        $delta = (int) $this->adjust['quantity'] * ($this->adjust['direction'] === 'in' ? 1 : -1);
        $pharmacy->adjust($batch, $delta, $this->adjust['reason'] === 'expired' ? 'expired' : 'adjustment', label($this->adjust['reason']).($this->adjust['note'] ? ': '.$this->adjust['note'] : ''));
        $this->showAdjust = false;
        $this->toast('Stock adjusted.');
    }

    public function writeOffExpired(PharmacyService $pharmacy): void
    {
        $this->authorize('pharmacy.inventory');
        $batches = MedicineBatch::where('quantity_available', '>', 0)->whereDate('expiry_date', '<', today())->get();
        foreach ($batches as $b) {
            $pharmacy->adjust($b, -$b->quantity_available, 'expired', 'Expired stock write-off');
        }
        $this->toast($batches->count().' expired batch(es) written off.', 'warning');
    }

    public function with(): array
    {
        $alertDays = (int) config('hms.pharmacy_expiry_alert_days');
        $batches = MedicineBatch::with(['medicine', 'supplier'])
            ->when($this->medicine, fn ($q) => $q->where('medicine_id', $this->medicine))
            ->when($this->filter === 'expiring', fn ($q) => $q->expiringWithin($alertDays)->whereDate('expiry_date', '>=', today()))
            ->when($this->filter === 'expired', fn ($q) => $q->where('quantity_available', '>', 0)->whereDate('expiry_date', '<', today()))
            ->when($this->filter === 'in_stock', fn ($q) => $q->where('quantity_available', '>', 0))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('batch_no', 'like', "%{$this->search}%")->orWhereHas('medicine', fn ($m) => $m->search($this->search))));

        $low = Medicine::withStock()->where('is_active', true)->get()->filter(fn ($m) => (int) $m->stock <= $m->reorder_level)->sortBy('stock')->values();

        return [
            'batches' => $this->view === 'batches' ? $this->applySort($batches)->paginate($this->perPage) : null,
            'movements' => $this->view === 'movements' ? StockMovement::with(['medicine', 'batch', 'user'])->when($this->medicine, fn ($q) => $q->where('medicine_id', $this->medicine))->latest()->paginate($this->perPage) : null,
            'low' => $low,
            'stats' => [
                'value' => (float) MedicineBatch::where('quantity_available', '>', 0)->selectRaw('sum(quantity_available * purchase_price) v')->value('v'),
                'expiring' => MedicineBatch::expiringWithin($alertDays)->whereDate('expiry_date', '>=', today())->count(),
                'expired' => MedicineBatch::where('quantity_available', '>', 0)->whereDate('expiry_date', '<', today())->count(),
                'low' => $low->count(),
            ],
            'medicineName' => $this->medicine ? Medicine::find($this->medicine)?->label : null,
            'alertDays' => $alertDays,
        ];
    }
}; ?>

<div>
    <x-page-header title="Stock & Expiry" :subtitle="$medicineName ?? 'Inventory'" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]">
        @if ($medicine)<button class="btn btn-light btn-sm" wire:click="$set('medicine', null)">Show all medicines</button>@endif
        @can('pharmacy.purchase')<a href="{{ route('tenant.pharmacy.purchase-orders', ['reorder' => 1]) }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-shopping-bag-3-line me-1"></i>Re-order low stock</a>@endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Stock value (cost)" :value="money($stats['value'])" icon="ri-money-rupee-circle-line" color="primary" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Low stock items" :value="$stats['low']" icon="ri-arrow-down-circle-line" color="danger" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Expiring ≤ {{ $alertDays }} days" :value="$stats['expiring']" icon="ri-alarm-warning-line" color="warning" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Expired (in stock)" :value="$stats['expired']" icon="ri-close-circle-line" color="danger" /></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header pb-0 border-0">
                    <ul class="nav nav-tabs card-header-tabs">
                        <li class="nav-item"><button class="nav-link {{ $view === 'batches' ? 'active' : '' }}" wire:click="$set('view', 'batches')">Batches</button></li>
                        <li class="nav-item"><button class="nav-link {{ $view === 'movements' ? 'active' : '' }}" wire:click="$set('view', 'movements')">Stock movements</button></li>
                    </ul>
                </div>
                @if ($view === 'batches')
                    <x-table-toolbar placeholder="Batch no. or medicine...">
                        <select class="form-select w-auto" wire:model.live="filter"><option value="">All batches</option><option value="in_stock">In stock</option><option value="expiring">Expiring soon</option><option value="expired">Expired</option></select>
                        @if ($stats['expired'])
                            @can('pharmacy.inventory')<button class="btn btn-light-danger btn-sm" x-on:click="$confirm('Write off all expired stock?', () => $wire.writeOffExpired())">Write off expired</button>@endcan
                        @endif
                    </x-table-toolbar>
                    <div class="table-responsive">
                        <table class="table table-hms table-hover mb-0">
                            <thead class="table-light"><tr><th>Medicine</th><th>Batch</th><th>Supplier</th><x-th field="expiry_date" :sort="$sortField" :dir="$sortDirection">Expiry</x-th><x-th field="quantity_available" :sort="$sortField" :dir="$sortDirection" class="text-end">Available</x-th><th class="text-end">Received</th><th class="text-end">MRP</th><th></th></tr></thead>
                            <tbody>
                                @forelse ($batches as $b)
                                    @php $days = (int) today()->diffInDays($b->expiry_date, false); @endphp
                                    <tr wire:key="b-{{ $b->id }}">
                                        <td>{{ $b->medicine->label }}</td>
                                        <td>{{ $b->batch_no }}</td>
                                        <td class="fs-12">{{ $b->supplier?->name ?? '—' }}</td>
                                        <td class="{{ $days < 0 ? 'text-danger fw-semibold' : ($days <= $alertDays ? 'text-warning fw-semibold' : '') }}">{{ fmt_date($b->expiry_date) }}<div class="fs-11">{{ $days < 0 ? 'expired '.abs($days).'d ago' : $days.' days left' }}</div></td>
                                        <td class="text-end fw-semibold">{{ $b->quantity_available }}</td>
                                        <td class="text-end text-muted">{{ $b->quantity_received }}</td>
                                        <td class="text-end">{{ money($b->sale_price) }}</td>
                                        <td class="text-end">@can('pharmacy.inventory')<button class="btn btn-sm btn-light-primary icon-btn-sm" title="Adjust" wire:click="openAdjust({{ $b->id }})"><i class="ri-equalizer-line"></i></button>@endcan</td>
                                    </tr>
                                @empty
                                    <x-empty-row :colspan="8" message="No batches." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">{{ $batches->links() }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hms mb-0">
                            <thead class="table-light"><tr><th>Date</th><th>Medicine</th><th>Batch</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Note</th><th>By</th></tr></thead>
                            <tbody>
                                @forelse ($movements as $m)
                                    <tr><td class="fs-12">{{ fmt_datetime($m->created_at) }}</td><td>{{ $m->medicine->label }}</td><td>{{ $m->batch?->batch_no }}</td><td><x-status :value="$m->type" /></td>
                                        <td class="text-end fw-semibold {{ $m->quantity < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td><td class="text-end">{{ $m->balance_after }}</td><td class="fs-12">{{ $m->note }}</td><td class="fs-12">{{ $m->user?->name }}</td></tr>
                                @empty
                                    <x-empty-row :colspan="8" message="No movements." />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">{{ $movements->links() }}</div>
                @endif
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0 text-danger"><i class="ri-arrow-down-circle-line me-1"></i>Re-order alerts</h6></div>
                <ul class="list-group list-group-flush" style="max-height: 520px; overflow-y: auto;">
                    @forelse ($low as $m)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="fs-13">{{ $m->label }}<div class="fs-11 text-muted">Re-order at {{ $m->reorder_level }}</div></span>
                            <span class="badge {{ (int) $m->stock <= 0 ? 'bg-danger' : 'bg-warning-subtle text-warning' }}">{{ (int) $m->stock }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted text-center py-4">All stock levels healthy.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>

    <x-modal wire:model="showAdjust" :title="'Adjust stock · '.($adjust['label'] ?? '')">
        <p class="text-muted">Available: <strong>{{ $adjust['available'] ?? 0 }}</strong></p>
        <div class="row">
            <x-form.select class="col-md-4" label="Direction" model="adjust.direction" :options="['out' => 'Remove (−)', 'in' => 'Add (+)']" :placeholder="false" />
            <x-form.input class="col-md-4" label="Quantity" model="adjust.quantity" type="number" min="1" />
            <x-form.select class="col-md-4" label="Reason" model="adjust.reason" :options="['damaged' => 'Damaged', 'expired' => 'Expired', 'count_correction' => 'Count correction', 'returned_to_supplier' => 'Returned to supplier', 'other' => 'Other']" :placeholder="false" />
            <x-form.input class="col-12" label="Note" model="adjust.note" />
        </div>
        @error('quantity')<div class="text-danger">{{ $message }}</div>@enderror
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveAdjust">Save adjustment</button></x-slot:footer>
    </x-modal>
</div>
