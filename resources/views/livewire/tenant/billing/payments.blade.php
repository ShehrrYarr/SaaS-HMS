<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Payment;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Payments')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'paid_at';

    protected array $sortable = ['paid_at', 'amount'];

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $method = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function with(): array
    {
        $filters = fn ($q) => $q
            ->when($this->from, fn ($q) => $q->whereDate('paid_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('paid_at', '<=', $this->to))
            ->when($this->method, fn ($q) => $q->where('method', $this->method))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('payment_no', 'like', "%{$this->search}%")->orWhere('reference', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $byMethod = $filters(Payment::query())->toBase()->selectRaw('method, sum(case when is_refund = 1 then -amount else amount end) total')->groupBy('method')->pluck('total', 'method');

        return [
            'payments' => $this->applySort($filters(Payment::with(['patient', 'invoice', 'receiver'])))->paginate($this->perPage),
            'byMethod' => $byMethod,
            'net' => $byMethod->sum(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Payments" subtitle="Receipts & refunds" :breadcrumbs="['Invoices' => route('tenant.billing.invoices')]" />

    <div class="row g-3 mb-4">
        <div class="col-md-3"><x-stat-card title="Net collected" :value="money($net)" icon="ri-wallet-3-line" color="success" /></div>
        @foreach ($byMethod as $m => $t)
            <div class="col-md-3 col-xl-2"><x-stat-card :title="label($m)" :value="money($t)" icon="ri-bank-card-line" color="info" /></div>
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Receipt #, reference or patient...">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <input type="date" class="form-control w-auto" wire:model.live="to">
            <select class="form-select w-auto" wire:model.live="method"><option value="">All methods</option>@foreach (['cash', 'card', 'bank_transfer', 'online', 'cheque', 'insurance'] as $m)<option value="{{ $m }}">{{ label($m) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Receipt</th><x-th field="paid_at" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Patient</th><th>Invoice</th><th>Method</th><th>Reference</th><th>Received by</th><x-th field="amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Amount</x-th></tr></thead>
                <tbody>
                    @forelse ($payments as $p)
                        <tr class="{{ $p->is_refund ? 'table-warning' : '' }}">
                            <td class="fw-semibold">{{ $p->payment_no }} @if ($p->is_refund)<span class="badge bg-warning">Refund</span>@endif</td>
                            <td>{{ fmt_datetime($p->paid_at) }}</td>
                            <td>{{ $p->patient->full_name }}</td>
                            <td><a href="{{ route('tenant.billing.show', $p->invoice) }}" wire:navigate>{{ $p->invoice->invoice_no }}</a></td>
                            <td>{{ label($p->method) }}</td>
                            <td class="fs-12">{{ $p->reference }}</td>
                            <td class="fs-12">{{ $p->receiver?->name }}</td>
                            <td class="text-end">{{ $p->is_refund ? '-' : '' }}{{ money($p->amount) }}</td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No payments in this period." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $payments->links() }}</div>
    </div>
</div>
