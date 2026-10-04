<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Subscription Invoices')] class extends Component
{
    use WithTable;

    #[Url]
    public string $status = '';

    protected array $sortable = ['number', 'due_date', 'total', 'created_at'];

    protected string $defaultSort = 'created_at';

    public ?int $payingId = null;

    public string $method = 'bank_transfer';

    public string $reference = '';

    public bool $showPay = false;

    public ?int $hospitalForInvoice = null;

    public function pay(int $id): void
    {
        $invoice = SubscriptionInvoice::findOrFail($id);
        $this->payingId = $id;
        $this->reference = (string) $invoice->payment_reference;
        $this->method = $invoice->payment_method ?: 'bank_transfer';
        $this->showPay = true;
    }

    public function confirmPayment(SubscriptionService $billing): void
    {
        $this->validate(['method' => 'required|string|max:30', 'reference' => 'nullable|string|max:100']);
        $billing->markPaid(SubscriptionInvoice::findOrFail($this->payingId), $this->method, $this->reference, auth()->id());
        $this->showPay = false;
        $this->toast('Payment recorded and subscription extended.');
    }

    public function reject(int $id): void
    {
        SubscriptionInvoice::findOrFail($id)->update(['status' => 'unpaid', 'notes' => 'Payment proof rejected on '.now()->toDateString()]);
        $this->toast('Payment proof rejected; invoice is unpaid again.', 'warning');
    }

    public function cancel(int $id): void
    {
        SubscriptionInvoice::findOrFail($id)->update(['status' => 'cancelled']);
        $this->toast('Invoice cancelled.', 'warning');
    }

    public function generate(SubscriptionService $billing): void
    {
        $this->validate(['hospitalForInvoice' => 'required|exists:hospitals,id']);
        $invoice = $billing->generateInvoice(Hospital::findOrFail($this->hospitalForInvoice));
        $this->hospitalForInvoice = null;
        $this->toast("Invoice {$invoice->number} generated.");
    }

    public function runBilling(SubscriptionService $billing): void
    {
        $result = $billing->runDailyBilling();
        $this->toast("Billing run: {$result['invoices']} invoices generated, {$result['suspended']} hospitals suspended.");
    }

    public function with(): array
    {
        $query = SubscriptionInvoice::with(['hospital', 'plan'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$this->search}%")
                ->orWhereHas('hospital', fn ($h) => $h->where('name', 'like', "%{$this->search}%"))));

        return [
            'invoices' => $this->applySort($query)->paginate($this->perPage),
            'hospitals' => Hospital::orderBy('name')->pluck('name', 'id'),
            'totals' => [
                'paid' => SubscriptionInvoice::where('status', 'paid')->sum('total'),
                'unpaid' => SubscriptionInvoice::where('status', 'unpaid')->sum('total'),
                'verify' => SubscriptionInvoice::where('status', 'pending_verification')->count(),
            ],
        ];
    }
}; ?>

<div>
    <x-page-header title="Invoices & Payments" subtitle="Subscription billing" :breadcrumbs="['Platform' => route('admin.dashboard')]">
        <button class="btn btn-light-warning btn-sm" x-on:click="$confirm('Run the billing cycle now? Renewal invoices will be created and overdue hospitals suspended.', () => $wire.runBilling(), { color: 'warning' })"><i class="ri-loop-right-line me-1"></i>Run billing now</button>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-md-4"><x-stat-card title="Collected" :value="money($totals['paid'], 'USD')" icon="ri-money-dollar-circle-line" color="success" /></div>
        <div class="col-md-4"><x-stat-card title="Outstanding" :value="money($totals['unpaid'], 'USD')" icon="ri-time-line" color="warning" /></div>
        <div class="col-md-4"><x-stat-card title="Awaiting verification" :value="$totals['verify']" icon="ri-shield-check-line" color="info" /></div>
    </div>

    <div class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <x-form.search-select class="col-md-6 mb-0" label="Generate invoice for hospital" model="hospitalForInvoice" :options="$hospitals" placeholder="Choose hospital" />
            <div class="col-md-3"><button class="btn btn-primary w-100" wire:click="generate"><i class="ri-file-add-line me-1"></i>Generate</button></div>
        </div>
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Search invoice # or hospital...">
            <select class="form-select w-auto" wire:model.live="status">
                <option value="">All</option>
                @foreach (['unpaid', 'pending_verification', 'paid', 'cancelled'] as $s)
                    <option value="{{ $s }}">{{ label($s) }}</option>
                @endforeach
            </select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <x-th field="number" :sort="$sortField" :dir="$sortDirection">Invoice</x-th>
                        <th>Hospital</th><th>Plan / period</th>
                        <x-th field="total" :sort="$sortField" :dir="$sortDirection">Total</x-th>
                        <x-th field="due_date" :sort="$sortField" :dir="$sortDirection">Due</x-th>
                        <th>Status</th><th>Payment</th><th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr wire:key="inv-{{ $inv->id }}">
                            <td class="fw-semibold">{{ $inv->number }}</td>
                            <td><a href="{{ route('admin.hospitals.show', $inv->hospital) }}" wire:navigate>{{ $inv->hospital?->name }}</a></td>
                            <td>{{ $inv->plan?->name }} <div class="fs-12 text-muted">{{ fmt_date($inv->period_start) }} – {{ fmt_date($inv->period_end) }}</div></td>
                            <td>{{ money($inv->total, $inv->currency) }}</td>
                            <td class="{{ $inv->status === 'unpaid' && $inv->due_date->isPast() ? 'text-danger' : '' }}">{{ fmt_date($inv->due_date) }}</td>
                            <td><x-status :value="$inv->status" /></td>
                            <td class="fs-12">
                                @if ($inv->payment_reference){{ label($inv->payment_method) }}: {{ $inv->payment_reference }}@endif
                                @if ($inv->proof_path)<div><a href="{{ route('files.show', ['path' => $inv->proof_path]) }}" target="_blank"><i class="ri-attachment-2"></i> Proof</a></div>@endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.invoices.pdf', $inv->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm" title="PDF"><i class="ri-file-pdf-2-line"></i></a>
                                @if (in_array($inv->status, ['unpaid', 'pending_verification']))
                                    <button class="btn btn-sm btn-light-success icon-btn-sm" title="Mark paid" wire:click="pay({{ $inv->id }})"><i class="ri-check-double-line"></i></button>
                                @endif
                                @if ($inv->status === 'pending_verification')
                                    <button class="btn btn-sm btn-light-warning icon-btn-sm" title="Reject proof" x-on:click="$confirm('Reject the payment proof?', () => $wire.reject({{ $inv->id }}), { color: 'warning' })"><i class="ri-close-line"></i></button>
                                @endif
                                @if ($inv->status === 'unpaid')
                                    <button class="btn btn-sm btn-light-danger icon-btn-sm" title="Cancel" x-on:click="$confirm('Cancel invoice {{ $inv->number }}?', () => $wire.cancel({{ $inv->id }}))"><i class="ri-delete-bin-line"></i></button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No invoices." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $invoices->links() }}</div>
    </div>

    <x-modal wire:model="showPay" title="Record payment">
        <x-form.select label="Payment method" model="method" :options="['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'card' => 'Card', 'online' => 'Online / wallet']" :placeholder="false" />
        <x-form.input label="Reference / transaction ID" model="reference" />
        <p class="text-muted fs-12 mb-0">Marking paid activates the hospital and extends its subscription to the invoice period end.</p>
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-success" wire:click="confirmPayment">Confirm payment</button>
        </x-slot:footer>
    </x-modal>
</div>
