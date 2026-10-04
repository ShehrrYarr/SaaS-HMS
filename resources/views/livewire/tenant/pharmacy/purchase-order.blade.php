<?php

use App\Livewire\Concerns\Toasts;
use App\Models\PurchaseOrder;
use App\Services\PharmacyService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Purchase Order')] class extends Component
{
    use Toasts;

    public PurchaseOrder $purchaseOrder;

    public array $received = [];

    public function mount(PurchaseOrder $purchaseOrder): void
    {
        $this->purchaseOrder = $purchaseOrder;
        $this->resetReceiving();
    }

    protected function resetReceiving(): void
    {
        $this->received = [];
        foreach ($this->purchaseOrder->items()->with('medicine')->get() as $item) {
            $this->received[$item->id] = [
                'quantity' => max(0, $item->quantity - $item->received_qty),
                'batch_no' => '', 'expiry_date' => '', 'mfg_date' => '', 'sale_price' => (string) $item->medicine->sale_price,
            ];
        }
    }

    public function setStatus(string $status): void
    {
        $this->authorize('pharmacy.purchase');
        abort_unless(in_array($status, ['ordered', 'cancelled']), 400);
        abort_if(in_array($this->purchaseOrder->status, ['received', 'partially_received']) && $status === 'cancelled', 422);
        $this->purchaseOrder->update(['status' => $status]);
        $this->toast('Purchase order '.label($status).'.');
    }

    public function receive(PharmacyService $pharmacy): void
    {
        $this->authorize('pharmacy.purchase');
        $this->validate([
            'received.*.quantity' => 'nullable|integer|min:0',
            'received.*.batch_no' => 'nullable|string|max:50',
            'received.*.expiry_date' => 'nullable|date|after:today',
            'received.*.mfg_date' => 'nullable|date|before_or_equal:today',
            'received.*.sale_price' => 'nullable|numeric|min:0',
        ]);
        foreach ($this->purchaseOrder->items as $item) {
            if ((int) ($this->received[$item->id]['quantity'] ?? 0) > $item->quantity - $item->received_qty) {
                $this->addError("received.{$item->id}.quantity", 'More than outstanding quantity.');

                return;
            }
        }
        $pharmacy->receive($this->purchaseOrder, $this->received);
        $this->purchaseOrder->refresh();
        $this->resetReceiving();
        $this->toast('Goods received and added to stock.');
    }

    public function with(): array
    {
        return ['po' => $this->purchaseOrder->load(['supplier', 'items.medicine', 'creator']), 'batches' => $this->purchaseOrder->load('items')->items->isEmpty() ? collect() : \App\Models\MedicineBatch::where('purchase_order_id', $this->purchaseOrder->id)->with('medicine')->get()];
    }
}; ?>

<div>
    <x-page-header :title="'PO '.$po->po_no" :subtitle="$po->supplier->name" :breadcrumbs="['Purchase Orders' => route('tenant.pharmacy.purchase-orders')]">
        <x-status :value="$po->status" />
        @if ($po->status === 'draft')<button class="btn btn-primary btn-sm" wire:click="setStatus('ordered')">Place order</button>@endif
        @if (in_array($po->status, ['draft', 'ordered']))<button class="btn btn-light-danger btn-sm" x-on:click="$confirm('Cancel this purchase order?', () => $wire.setStatus('cancelled'))">Cancel PO</button>@endif
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-body">
                    <dl class="row fs-13 mb-0">
                        <dt class="col-5">Supplier</dt><dd class="col-7">{{ $po->supplier->name }}<div class="text-muted">{{ $po->supplier->phone }} {{ $po->supplier->email }}</div></dd>
                        <dt class="col-5">Order date</dt><dd class="col-7">{{ fmt_date($po->order_date) }}</dd>
                        <dt class="col-5">Expected</dt><dd class="col-7">{{ fmt_date($po->expected_date) }}</dd>
                        <dt class="col-5">Received</dt><dd class="col-7">{{ fmt_datetime($po->received_at) }}</dd>
                        <dt class="col-5">Created by</dt><dd class="col-7">{{ $po->creator?->name }}</dd>
                        <dt class="col-5">Subtotal</dt><dd class="col-7">{{ money($po->subtotal) }}</dd>
                        <dt class="col-5">Tax</dt><dd class="col-7">{{ money($po->tax) }}</dd>
                        <dt class="col-5">Total</dt><dd class="col-7 fw-bold">{{ money($po->total) }}</dd>
                    </dl>
                    @if ($po->notes)<p class="fs-13 text-muted mt-2 mb-0">{{ $po->notes }}</p>@endif
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">{{ in_array($po->status, ['ordered', 'partially_received']) ? 'Receive goods (GRN)' : 'Items' }}</h5></div>
                <div class="table-responsive">
                    <table class="table table-hms align-middle mb-0">
                        <thead class="table-light"><tr><th>Medicine</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Cost</th>
                            @if (in_array($po->status, ['ordered', 'partially_received']))<th>Receive qty</th><th>Batch</th><th>Expiry</th><th>MRP</th>@endif</tr></thead>
                        <tbody>
                            @foreach ($po->items as $item)
                                <tr wire:key="poi-{{ $item->id }}">
                                    <td>{{ $item->medicine->label }}</td>
                                    <td class="text-end">{{ $item->quantity }}</td>
                                    <td class="text-end {{ $item->received_qty >= $item->quantity ? 'text-success' : '' }}">{{ $item->received_qty }}</td>
                                    <td class="text-end">{{ money($item->unit_price) }}</td>
                                    @if (in_array($po->status, ['ordered', 'partially_received']))
                                        @if ($item->received_qty < $item->quantity)
                                            <td><input type="number" class="form-control form-control-sm @error('received.'.$item->id.'.quantity') is-invalid @enderror" style="width: 80px;" wire:model="received.{{ $item->id }}.quantity"></td>
                                            <td><input type="text" class="form-control form-control-sm @error('received.'.$item->id.'.batch_no') is-invalid @enderror" wire:model="received.{{ $item->id }}.batch_no"></td>
                                            <td><input type="date" class="form-control form-control-sm @error('received.'.$item->id.'.expiry_date') is-invalid @enderror" wire:model="received.{{ $item->id }}.expiry_date"></td>
                                            <td><input type="number" step="0.01" class="form-control form-control-sm" style="width: 90px;" wire:model="received.{{ $item->id }}.sale_price"></td>
                                        @else
                                            <td colspan="4" class="text-success fs-13"><i class="ri-check-line"></i> Fully received</td>
                                        @endif
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if (in_array($po->status, ['ordered', 'partially_received']))
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <span class="text-danger fs-13">{{ $errors->first() }}</span>
                        <button class="btn btn-success" wire:click="receive"><i class="ri-inbox-archive-line me-1"></i>Receive into stock</button>
                    </div>
                @endif
            </div>
            @if ($batches->isNotEmpty())
                <div class="card mt-4 mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">Batches created from this PO</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($batches as $b)<li class="list-group-item fs-13">{{ $b->medicine->label }} · batch {{ $b->batch_no }} · exp {{ fmt_date($b->expiry_date) }} · {{ $b->quantity_received }} units</li>@endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</div>
