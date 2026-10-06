<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PharmacySale;
use Carbon\CarbonPeriod;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Financial Reports')] class extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function preset(string $p): void
    {
        [$this->from, $this->to] = match ($p) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'week' => [today()->startOfWeek()->toDateString(), today()->toDateString()],
            'last_month' => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'year' => [today()->startOfYear()->toDateString(), today()->toDateString()],
            default => [today()->startOfMonth()->toDateString(), today()->toDateString()],
        };
    }

    protected function taxRows(): array
    {
        $rows = InvoiceItem::query()->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$this->from, $this->to]))
            ->toBase()->selectRaw('service_type, sum(quantity * unit_price - discount) taxable, sum(tax_amount) tax, sum(total) total')->groupBy('service_type')->get()
            ->map(fn ($r) => ['label' => strtoupper($r->service_type), 'taxable' => (float) $r->taxable, 'tax' => (float) $r->tax, 'total' => (float) $r->total])->all();

        $walkIn = PharmacySale::whereNull('patient_id')->where('status', 'completed')->whereBetween('created_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->toBase()->selectRaw('sum(subtotal - discount) taxable, sum(tax) tax, sum(total) total')->first();
        if ($walkIn && $walkIn->total > 0) {
            $rows[] = ['label' => 'PHARMACY (walk-in counter)', 'taxable' => (float) $walkIn->taxable, 'tax' => (float) $walkIn->tax, 'total' => (float) $walkIn->total];
        }

        return $rows;
    }

    public function exportTax()
    {
        $this->authorize('billing.reports');
        $rows = $this->taxRows();
        $label = hospital()->tax_label;

        return response()->streamDownload(function () use ($rows, $label) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Service', 'Taxable value', $label, 'Gross']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['label'], rupees($r['taxable']), rupees($r['tax']), rupees($r['total'])]);
            }
            fclose($out);
        }, 'tax-report-'.$this->from.'-to-'.$this->to.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(): array
    {
        $range = [$this->from.' 00:00:00', $this->to.' 23:59:59'];
        $invoices = Invoice::where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$this->from, $this->to]);
        $payments = Payment::whereBetween('paid_at', $range);

        $collected = (float) (clone $payments)->where('is_refund', false)->sum('amount');
        $refunds = (float) (clone $payments)->where('is_refund', true)->sum('amount');
        $walkInPharmacy = (float) PharmacySale::whereNull('patient_id')->where('status', 'completed')->where('payment_method', '!=', 'ipd_credit')->whereBetween('created_at', $range)->sum('total');
        $expenses = (float) Expense::whereBetween('expense_date', [$this->from, $this->to])->sum('amount');

        $byService = InvoiceItem::query()->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$this->from, $this->to]))
            ->toBase()->selectRaw('service_type, sum(total) total')->groupBy('service_type')->orderByDesc('total')->pluck('total', 'service_type');
        // Net patient money per bank / cash account (receipts, counter sales, deposits, insurance settlements − refunds).
        $byAccount = BankTransaction::whereBetween('transacted_at', $range)->whereIn('type', ['receipt', 'insurance', 'pharmacy', 'deposit', 'refund'])->toBase()
            ->selectRaw("bank_account_id, sum(case when direction = 'in' then amount else -amount end) total")->groupBy('bank_account_id')->pluck('total', 'bank_account_id');
        $accountNames = BankAccount::whereIn('id', $byAccount->keys())->get()->mapWithKeys(fn ($a) => [$a->id => $a->label]);

        $days = collect(CarbonPeriod::create($this->from, min(\Carbon\Carbon::parse($this->to), \Carbon\Carbon::parse($this->from)->addDays(92))))->map->toDateString();
        $dailyIn = Payment::whereBetween('paid_at', $range)->where('is_refund', false)->get(['paid_at', 'amount'])->groupBy(fn ($p) => $p->paid_at->toDateString())->map->sum('amount');
        $dailyOut = Expense::whereBetween('expense_date', [$this->from, $this->to])->get(['expense_date', 'amount'])->groupBy(fn ($e) => $e->expense_date->toDateString())->map->sum('amount');

        $outstanding = Invoice::whereIn('status', ['unpaid', 'partial'])->get(['invoice_date', 'total', 'paid_amount', 'insurance_amount']);
        $aging = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($outstanding as $inv) {
            $age = $inv->invoice_date->diffInDays(today());
            $bucket = $age <= 30 ? '0-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : '90+'));
            $aging[$bucket] += max(0, $inv->balance);
        }

        $doctors = InvoiceItem::query()->whereNotNull('doctor_id')->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$this->from, $this->to]))
            ->with('doctor')->get()->groupBy('doctor_id')->map(fn ($items) => ['name' => $items->first()->doctor?->display_name, 'count' => $items->count(), 'revenue' => $items->sum('total')])->sortByDesc('revenue')->values();

        return [
            'kpi' => [
                'billed' => (float) (clone $invoices)->sum('total'),
                'discount' => (float) (clone $invoices)->sum('discount') + (float) InvoiceItem::query()->whereHas('invoice', fn ($q) => $q->where('status', '!=', 'cancelled')->whereBetween('invoice_date', [$this->from, $this->to]))->sum('discount'),
                'tax' => (float) (clone $invoices)->sum('tax'),
                'collected' => $collected - $refunds + $walkInPharmacy,
                'refunds' => $refunds,
                'expenses' => $expenses,
                'net' => $collected - $refunds + $walkInPharmacy - $expenses,
                'outstanding' => array_sum($aging),
            ],
            'serviceChart' => $byService->isNotEmpty() ? ['chart' => ['type' => 'donut', 'height' => 300], 'series' => $byService->values()->map(fn ($v) => rupees($v)), 'labels' => $byService->keys()->map(fn ($k) => strtoupper($k)), 'legend' => ['position' => 'bottom']] : null,
            'methodChart' => $byAccount->isNotEmpty() ? ['chart' => ['type' => 'bar', 'height' => 300], 'series' => [['name' => 'Collected', 'data' => $byAccount->values()->map(fn ($v) => rupees($v))]], 'xaxis' => ['categories' => $byAccount->keys()->map(fn ($k) => $accountNames[$k] ?? 'Account #'.$k)], 'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 4]]] : null,
            'trendChart' => ['chart' => ['type' => 'area', 'height' => 300], 'series' => [
                ['name' => 'Collections', 'data' => $days->map(fn ($d) => rupees($dailyIn[$d] ?? 0))->values()],
                ['name' => 'Expenses', 'data' => $days->map(fn ($d) => rupees($dailyOut[$d] ?? 0))->values()],
            ], 'xaxis' => ['categories' => $days->map(fn ($d) => \Carbon\Carbon::parse($d)->format('d M'))->values()], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 2]],
            'taxRows' => $this->taxRows(),
            'aging' => $aging,
            'doctors' => $doctors,
        ];
    }
}; ?>

<div>
    <x-page-header title="Financial Reports" :subtitle="fmt_date($from).' – '.fmt_date($to)" :breadcrumbs="['Billing' => route('tenant.billing.invoices')]">
        <div class="d-flex flex-wrap gap-1">
            @foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'] as $k => $l)
                <button class="btn btn-sm btn-light" wire:click="preset('{{ $k }}')">{{ $l }}</button>
            @endforeach
        </div>
        <input type="date" class="form-control form-control-sm w-auto" wire:model.live="from">
        <input type="date" class="form-control form-control-sm w-auto" wire:model.live="to">
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Gross billed" :value="money($kpi['billed'])" icon="ri-file-list-3-line" color="primary" :hint="'Discounts '.money($kpi['discount']).' · '.hospital()->tax_label.' '.money($kpi['tax'])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Net collections" :value="money($kpi['collected'])" icon="ri-money-rupee-circle-line" color="success" :hint="'Applied to bills · refunds '.money($kpi['refunds'])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Expenses" :value="money($kpi['expenses'])" icon="ri-shopping-bag-line" color="danger" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card title="Net cash flow" :value="money($kpi['net'])" icon="ri-scales-3-line" :color="$kpi['net'] >= 0 ? 'success' : 'danger'" :hint="'Outstanding receivables '.money($kpi['outstanding'])" /></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-8"><div class="card h-100 mb-0"><div class="card-header"><h6 class="card-title mb-0">Collections vs expenses</h6></div><div class="card-body" wire:key="trend-{{ $from }}-{{ $to }}"><div x-data="apexChart(@js($trendChart))" wire:ignore></div></div></div></div>
        <div class="col-xl-4"><div class="card h-100 mb-0"><div class="card-header"><h6 class="card-title mb-0">Revenue by service</h6></div><div class="card-body" wire:key="svc-{{ $from }}-{{ $to }}">@if ($serviceChart)<div x-data="apexChart(@js($serviceChart))" wire:ignore></div>@else<p class="text-muted text-center py-5">No billing in period.</p>@endif</div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">{{ hospital()->tax_label }} report</h6>
                    <button class="btn btn-sm btn-light-primary" wire:click="exportTax"><i class="ri-download-2-line me-1"></i>CSV</button>
                </div>
                <table class="table table-hms mb-0">
                    <thead class="table-light"><tr><th>Service</th><th class="text-end">Taxable value</th><th class="text-end">{{ hospital()->tax_label }}</th><th class="text-end">Gross</th></tr></thead>
                    <tbody>
                        @forelse ($taxRows as $r)<tr><td>{{ $r['label'] }}</td><td class="text-end">{{ money($r['taxable']) }}</td><td class="text-end">{{ money($r['tax']) }}</td><td class="text-end">{{ money($r['total']) }}</td></tr>@empty<x-empty-row :colspan="4" />@endforelse
                    </tbody>
                    <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end">{{ money(collect($taxRows)->sum('taxable')) }}</td><td class="text-end">{{ money(collect($taxRows)->sum('tax')) }}</td><td class="text-end">{{ money(collect($taxRows)->sum('total')) }}</td></tr></tfoot>
                </table>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Receivables ageing</h6></div>
                <div class="card-body row text-center">
                    @foreach ($aging as $bucket => $amount)
                        <div class="col-3"><div class="text-muted fs-12">{{ $bucket }} days</div><strong class="{{ $bucket === '90+' && $amount > 0 ? 'text-danger' : '' }}">{{ money($amount) }}</strong></div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Collections by bank / cash account</h6><small class="text-muted">Money actually received, including IPD advance deposits not yet applied to a bill, so it can differ from Net collections.</small></div>
                <div class="card-body" wire:key="meth-{{ $from }}-{{ $to }}">@if ($methodChart)<div x-data="apexChart(@js($methodChart))" wire:ignore></div>@else<p class="text-muted text-center py-4 mb-0">No payments in period.</p>@endif</div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Doctor-wise revenue</h6></div>
                <table class="table table-hms mb-0">
                    <thead class="table-light"><tr><th>Doctor</th><th class="text-end">Services</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                        @forelse ($doctors as $d)<tr><td>{{ $d['name'] }}</td><td class="text-end">{{ $d['count'] }}</td><td class="text-end">{{ money($d['revenue']) }}</td></tr>@empty<x-empty-row :colspan="3" />@endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
