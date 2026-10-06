<?php

use App\Livewire\Concerns\WithTable;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\IpdCharge;
use App\Models\PharmacySale;
use App\Services\BillingService;
use App\Services\LedgerService;
use App\Services\PharmacyService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pharmacy Sales')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at', 'total'];

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $method = '';

    public ?int $viewing = null;

    public bool $showView = false;

    /** Account the money is paid back from when a sale is returned. */
    public string $refundAccount = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->subDays(6)->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function view(int $id): void
    {
        $this->viewing = $id;
        $sale = PharmacySale::find($id);
        $this->refundAccount = (string) (BankAccount::active()->find($sale?->bank_account_id)?->id ?? BankAccount::cash()->id);
        $this->showView = true;
    }

    /** Full return: restock every batch and refund/credit the linked bill. */
    public function returnSale(int $id, PharmacyService $pharmacy, BillingService $billing, LedgerService $ledger): void
    {
        $this->authorize('pharmacy.sell');
        $sale = PharmacySale::with('items.batch')->findOrFail($id);
        if ($sale->status !== 'completed') {
            return;
        }
        if ($sale->ipd_admission_id && IpdCharge::where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)->where('billed', true)->exists()) {
            $this->toast("{$sale->sale_no} is already on the patient's final IPD bill, so it can't be returned here. Adjust that bill instead.", 'error');

            return;
        }
        if ($sale->payment_method !== 'ipd_credit') {
            $this->validate(['refundAccount' => ['required', bank_account_exists()]], [], ['refundAccount' => 'refund account']);
        }

        DB::transaction(function () use ($sale, $pharmacy, $billing, $ledger) {
            foreach ($sale->items as $item) {
                $pharmacy->move($item->batch, $item->quantity, 'return', $sale, "Return of {$sale->sale_no}");
            }
            $sale->update(['status' => 'returned']);
            if ($sale->prescription_id) {
                $pharmacy->unapplyFromPrescription($sale);
            }

            $invoice = Invoice::whereHas('items', fn ($q) => $q->where('source_type', $sale->getMorphClass())->where('source_id', $sale->id))->first();
            if ($invoice && $invoice->paid_amount > 0) {
                $billing->addPayment($invoice, $invoice->paid_amount, $this->refundAccount, $sale->sale_no, true, 'Pharmacy return');
                $billing->cancel($invoice->fresh(), 'Pharmacy sale returned');
            } elseif (! $invoice && $sale->payment_method !== 'ipd_credit' && $sale->total > 0) {
                $ledger->moneyOut($this->refundAccount, $sale->total, 'refund', $sale, "Return of pharmacy sale {$sale->sale_no}");
            }
            if ($sale->ipd_admission_id) {
                IpdCharge::where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)->where('billed', false)->delete();
            }
        });

        $this->showView = false;
        $this->toast("Sale {$sale->sale_no} returned and stock restored.", 'warning');
    }

    public function with(): array
    {
        $filters = fn ($q) => $q
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->when($this->method === 'ipd_credit', fn ($q) => $q->where('payment_method', 'ipd_credit'))
            ->when(is_numeric($this->method), fn ($q) => $q->where('bank_account_id', $this->method))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('sale_no', 'like', "%{$this->search}%")->orWhere('customer_name', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));
        $query = $filters(PharmacySale::with(['patient', 'seller', 'account'])->withCount('items'));

        return [
            'sales' => $this->applySort($query)->paginate($this->perPage),
            'summary' => $filters(PharmacySale::query())->where('status', 'completed')->toBase()->selectRaw('count(*) c, sum(total) t')->first(),
            'sale' => $this->viewing ? PharmacySale::with(['items.medicine', 'items.batch', 'patient', 'seller', 'account'])->find($this->viewing) : null,
            'accounts' => BankAccount::options(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Pharmacy Sales" subtitle="Transactions" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]">
        @can('pharmacy.sell')<a href="{{ route('tenant.pharmacy.pos') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i>New sale</a>@endcan
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Sale #, customer or patient...">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <input type="date" class="form-control w-auto" wire:model.live="to">
            <select class="form-select w-auto" wire:model.live="method"><option value="">All payments</option>@foreach ($accounts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach<option value="ipd_credit">IPD bill (credit)</option></select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Sale</th><x-th field="created_at" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Customer</th><th>Items</th><th>Payment</th><x-th field="total" :sort="$sortField" :dir="$sortDirection">Total</x-th><th>By</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($sales as $s)
                        <tr wire:key="ps-{{ $s->id }}">
                            <td class="fw-semibold">{{ $s->sale_no }}</td>
                            <td>{{ fmt_datetime($s->created_at) }}</td>
                            <td>{{ $s->patient?->full_name ?? ($s->customer_name ?: 'Walk-in') }}</td>
                            <td>{{ $s->items_count }}</td>
                            <td>{{ $s->paymentLabel() }}</td>
                            <td>{{ money($s->total) }}</td>
                            <td class="fs-12">{{ $s->seller?->name }}</td>
                            <td><x-status :value="$s->status" /></td>
                            <td class="text-end text-nowrap">
                                <button title="View" aria-label="View" class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="view({{ $s->id }})"><i class="ri-eye-line"></i></button>
                                <a title="Print" aria-label="Print" href="{{ route('tenant.pharmacy.receipt', $s->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm"><i class="ri-printer-line"></i></a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="9" message="No sales in this period." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between gap-2"><span class="text-muted fs-13">{{ $summary->c ?? 0 }} sales · {{ money($summary->t ?? 0) }}</span>{{ $sales->links() }}</div>
    </div>

    <x-modal wire:model="showView" :title="'Sale '.($sale?->sale_no ?? '')" size="lg">
        @if ($sale)
            <p class="mb-2">{{ $sale->patient?->full_name ?? ($sale->customer_name ?: 'Walk-in') }} · {{ fmt_datetime($sale->created_at) }} · {{ $sale->paymentLabel() }} <x-status :value="$sale->status" /></p>
            <table class="table table-sm">
                <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach ($sale->items as $i)
                        <tr><td>{{ $i->medicine->label }}</td><td>{{ $i->batch->batch_no }}</td><td>{{ fmt_date($i->batch->expiry_date) }}</td><td class="text-end">{{ $i->quantity }}</td><td class="text-end">{{ money($i->unit_price) }}</td><td class="text-end">{{ money($i->total) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="5" class="text-end">Discount</td><td class="text-end">- {{ money($sale->discount) }}</td></tr>
                    <tr><td colspan="5" class="text-end">Tax</td><td class="text-end">{{ money($sale->tax) }}</td></tr>
                    <tr><th colspan="5" class="text-end">Total</th><th class="text-end">{{ money($sale->total) }}</th></tr>
                </tfoot>
            </table>
        @endif
        <x-slot:footer>
            @if ($sale?->status === 'completed')
                @can('pharmacy.sell')
                    <div class="d-flex align-items-center gap-2 me-auto">
                        @if ($sale->payment_method !== 'ipd_credit')
                            <select class="form-select form-select-sm w-auto @error('refundAccount') is-invalid @enderror" wire:model="refundAccount" title="Refund paid from">
                                @foreach ($accounts as $id => $name)<option value="{{ $id }}">Refund from {{ $name }}</option>@endforeach
                            </select>
                        @endif
                        <button class="btn btn-light-danger" x-on:click="$confirm('Return the whole sale and restock?', () => $wire.returnSale({{ $sale->id }}))">Return sale</button>
                    </div>
                @endcan
            @endif
            <button class="btn btn-light" x-on:click="show = false">Close</button>
        </x-slot:footer>
    </x-modal>
</div>
