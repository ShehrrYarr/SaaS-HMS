<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Invoice;
use App\Models\Tpa;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Invoices')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'invoice_date';

    protected array $sortable = ['invoice_date', 'total', 'invoice_no'];

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $tpa = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function with(): array
    {
        $filters = fn ($q) => $q
            ->when($this->status === 'due', fn ($q) => $q->whereIn('status', ['unpaid', 'partial']))
            ->when($this->status && $this->status !== 'due', fn ($q) => $q->where('status', $this->status))
            ->when($this->type, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('service_type', $this->type)))
            ->when($this->tpa, fn ($q) => $q->where('tpa_id', $this->tpa))
            ->when($this->from, fn ($q) => $q->whereDate('invoice_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('invoice_date', '<=', $this->to))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('invoice_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $totals = $filters(Invoice::query())->where('status', '!=', 'cancelled')->toBase()
            ->selectRaw('count(*) c, coalesce(sum(total),0) total, coalesce(sum(paid_amount),0) paid, coalesce(sum(insurance_amount),0) ins')->first();

        return [
            'invoices' => $this->applySort($filters(Invoice::with(['patient', 'tpa'])))->paginate($this->perPage),
            'totals' => $totals,
            'tpas' => Tpa::orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Invoices" subtitle="Patient billing">
        @can('billing.create')<a href="{{ route('tenant.billing.create') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i>New invoice</a>@endcan
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Billed" :value="money($totals->total)" icon="ri-file-list-3-line" color="primary" :hint="$totals->c.' invoices'" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Collected" :value="money($totals->paid)" icon="ri-money-rupee-circle-line" color="success" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Insurance / TPA" :value="money($totals->ins)" icon="ri-shield-cross-line" color="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Outstanding" :value="money($totals->total - $totals->paid - $totals->ins)" icon="ri-time-line" color="warning" /></div>
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Invoice # or patient...">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <input type="date" class="form-control w-auto" wire:model.live="to">
            <select class="form-select w-auto" wire:model.live="status"><option value="">Any status</option><option value="due">Due (unpaid + partial)</option>@foreach (['unpaid', 'partial', 'paid', 'cancelled', 'draft'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="type"><option value="">All services</option>@foreach (['opd', 'ipd', 'pharmacy', 'lab', 'radiology', 'ot', 'bloodbank', 'service'] as $t)<option value="{{ $t }}">{{ strtoupper($t) }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="tpa"><option value="">All payers</option>@foreach ($tpas as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><x-th field="invoice_no" :sort="$sortField" :dir="$sortDirection">Invoice</x-th><x-th field="invoice_date" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Patient</th><th>Payer</th><x-th field="total" :sort="$sortField" :dir="$sortDirection" class="text-end">Total</x-th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr wire:key="inv-{{ $inv->id }}">
                            <td class="fw-semibold">{{ $inv->invoice_no }}</td>
                            <td>{{ fmt_date($inv->invoice_date) }}</td>
                            <td><a href="{{ route('tenant.patients.show', $inv->patient) }}" wire:navigate>{{ $inv->patient->full_name }}</a><div class="fs-12 text-muted">{{ $inv->patient->uhid }}</div></td>
                            <td class="fs-13">{{ $inv->tpa?->name ?? 'Self' }}</td>
                            <td class="text-end">{{ money($inv->total) }}</td>
                            <td class="text-end">{{ money($inv->paid_amount) }}</td>
                            <td class="text-end {{ $inv->balance > 0 && $inv->status !== 'cancelled' ? 'text-danger fw-semibold' : '' }}">{{ $inv->status === 'cancelled' ? '—' : money(max(0, $inv->balance)) }}</td>
                            <td><x-status :value="$inv->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tenant.billing.pdf', $inv->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm"><i class="ri-printer-line"></i></a>
                                <a href="{{ route('tenant.billing.show', $inv) }}" wire:navigate class="btn btn-sm btn-light-primary">Open</a>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="9" message="No invoices for the selected filters." icon="ri-bill-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $invoices->links() }}</div>
    </div>
</div>
