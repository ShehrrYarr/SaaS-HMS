<?php

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\IpdAdmission;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PharmacySale;
use App\Services\LedgerService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Account Statement')] class extends Component
{
    use WithPagination;

    public BankAccount $account;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $type = '';

    public int $perPage = 50;

    public function mount(BankAccount $account): void
    {
        $this->account = $account;
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function updated($field): void
    {
        if (in_array($field, ['from', 'to', 'type'])) {
            $this->resetPage();
        }
    }

    protected function rows()
    {
        return BankTransaction::where('bank_account_id', $this->account->id)
            ->whereBetween('transacted_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when($this->type, fn ($q) => $q->where('type', $this->type));
    }

    public function export(LedgerService $ledger)
    {
        $rows = $this->rows()->orderBy('transacted_at')->orderBy('id')->get();
        $balance = $ledger->balance($this->account, $this->from.' 00:00:00');
        $name = str($this->account->name)->slug().'-statement-'.$this->from.'-to-'.$this->to.'.csv';

        return response()->streamDownload(function () use ($rows, $balance) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Details', 'Reference', 'Type', 'In', 'Out', 'Balance']);
            fputcsv($out, [$this->from, 'Opening balance', '', '', '', '', $balance]);
            foreach ($rows as $tx) {
                $balance += $tx->signed_amount;
                fputcsv($out, [$tx->transacted_at->format('Y-m-d H:i'), $tx->description, $tx->reference, label($tx->type), $tx->direction === 'in' ? $tx->amount : '', $tx->direction === 'out' ? $tx->amount : '', $balance]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** Where a ledger line came from, as [label, url]. */
    public function sourceLink(BankTransaction $tx): ?array
    {
        $source = $tx->source;

        return match (true) {
            $source instanceof Payment && $source->invoice_id => [$source->payment_no, route('tenant.billing.show', $source->invoice_id)],
            $source instanceof IpdAdmission => [$source->admission_no, route('tenant.ipd.show', $source)],
            $source instanceof Expense => ['Expense', route('tenant.billing.expenses', ['from' => $source->expense_date->toDateString(), 'to' => $source->expense_date->toDateString()])],
            $source instanceof Payroll => ['Payroll '.$source->month, route('tenant.hr.payroll', ['month' => $source->month])],
            $source instanceof PharmacySale => [$source->sale_no, route('tenant.pharmacy.sales', ['from' => $source->created_at->toDateString(), 'to' => $source->created_at->toDateString()])],
            default => null,
        };
    }

    public function with(): array
    {
        $opening = app(LedgerService::class)->balance($this->account, $this->from.' 00:00:00');
        $sums = $this->rows()->toBase()->selectRaw("sum(case when direction = 'in' then amount else 0 end) money_in, sum(case when direction = 'out' then amount else 0 end) money_out")->first();
        $page = $this->rows()->with(['source', 'creator'])->orderBy('transacted_at')->orderBy('id')->paginate($this->perPage);

        // Running balance: opening + every earlier line of the range (earlier pages included).
        $skipped = ($page->currentPage() - 1) * $page->perPage();
        $before = $skipped > 0
            ? (int) DB::query()->fromSub($this->rows()->orderBy('transacted_at')->orderBy('id')->limit($skipped)
                ->select(DB::raw("case when direction = 'in' then amount else -amount end as signed")), 't')->sum('signed')
            : 0;

        return [
            'opening' => $opening,
            'moneyIn' => (int) ($sums->money_in ?? 0),
            'moneyOut' => (int) ($sums->money_out ?? 0),
            'transactions' => $page,
            'startBalance' => $opening + $before,
            'current' => $this->account->balance,
            'types' => BankTransaction::where('bank_account_id', $this->account->id)->distinct()->orderBy('type')->pluck('type')->mapWithKeys(fn ($t) => [$t => label($t)])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$account->label" subtitle="Statement" :breadcrumbs="['Banks & Cash' => route('tenant.banks.index')]">
        <button class="btn btn-light btn-sm no-print" wire:click="export"><i class="ri-download-2-line me-1"></i>CSV</button>
        <button class="btn btn-light btn-sm no-print" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
    </x-page-header>

    <div class="card">
        <div class="card-body d-flex flex-wrap gap-4 align-items-center">
            <div class="avatar avatar-item rounded-2 {{ $account->isCash() ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary' }}"><i class="{{ $account->isCash() ? 'ri-money-rupee-circle-line' : 'ri-bank-line' }} fs-18"></i></div>
            <div class="flex-grow-1 fs-13">
                @if ($account->isCash())
                    <strong>Cash in hand</strong>
                @else
                    <strong>{{ $account->name }}</strong>@if ($account->branch) · {{ $account->branch }}@endif<br>
                    <span class="text-muted">{{ $account->account_title }} · {{ $account->account_number }}@if ($account->iban) · IBAN {{ $account->iban }}@endif</span>
                @endif
                @unless ($account->is_active)<span class="badge bg-secondary-subtle text-secondary ms-1">Inactive</span>@endunless
            </div>
            <div class="text-end"><div class="fs-12 text-muted">Current balance</div><div class="fs-4 fw-bold {{ $current < 0 ? 'text-danger' : '' }}">{{ money($current) }}</div></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat-card title="Opening ({{ fmt_date($from) }})" :value="money($opening)" icon="ri-login-box-line" color="secondary" /></div>
        <div class="col-6 col-xl-3"><x-stat-card title="Money in" :value="money($moneyIn)" icon="ri-arrow-down-circle-line" color="success" /></div>
        <div class="col-6 col-xl-3"><x-stat-card title="Money out" :value="money($moneyOut)" icon="ri-arrow-up-circle-line" color="danger" /></div>
        @if ($type)
            <div class="col-6 col-xl-3"><x-stat-card title="Net of filtered lines" :value="money($moneyIn - $moneyOut)" icon="ri-filter-3-line" color="primary" /></div>
        @else
            <div class="col-6 col-xl-3"><x-stat-card title="Closing ({{ fmt_date($to) }})" :value="money($opening + $moneyIn - $moneyOut)" icon="ri-logout-box-r-line" color="primary" /></div>
        @endif
    </div>

    <div class="card mb-0">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center no-print">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <input type="date" class="form-control w-auto" wire:model.live="to">
            <select class="form-select w-auto" wire:model.live="type"><option value="">All types</option>@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </div>
        <div class="table-responsive">
            <table class="table table-hms mb-0">
                <thead class="table-light"><tr><th>Date</th><th>Details</th><th>Type</th><th>Source</th><th class="text-end">In</th><th class="text-end">Out</th>@unless ($type)<th class="text-end">Balance</th>@endunless</tr></thead>
                <tbody>
                    @unless ($type)
                        <tr class="table-light"><td>{{ fmt_date($from) }}</td><td colspan="5" class="fw-semibold">Opening balance</td><td class="text-end fw-semibold">{{ money($startBalance) }}</td></tr>
                    @endunless
                    @php $balance = $startBalance; @endphp
                    @forelse ($transactions as $tx)
                        @php $balance += $tx->signed_amount; $link = $this->sourceLink($tx); @endphp
                        <tr wire:key="tx-{{ $tx->id }}">
                            <td class="text-nowrap">{{ fmt_datetime($tx->transacted_at) }}</td>
                            <td>{{ $tx->description }}@if ($tx->reference)<div class="fs-12 text-muted">Ref: {{ $tx->reference }}</div>@endif @if ($tx->creator)<div class="fs-12 text-muted">by {{ $tx->creator->name }}</div>@endif</td>
                            <td><span class="badge bg-light text-body">{{ label($tx->type) }}</span></td>
                            <td class="fs-12">@if ($link)<a href="{{ $link[1] }}" wire:navigate>{{ $link[0] }}</a>@endif</td>
                            <td class="text-end text-success">{{ $tx->direction === 'in' ? money($tx->amount) : '' }}</td>
                            <td class="text-end text-danger">{{ $tx->direction === 'out' ? money($tx->amount) : '' }}</td>
                            @unless ($type)<td class="text-end fw-semibold {{ $balance < 0 ? 'text-danger' : '' }}">{{ money($balance) }}</td>@endunless
                        </tr>
                    @empty
                        <x-empty-row :colspan="7" message="No transactions in this period." icon="ri-file-list-3-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $transactions->links() }}</div>
    </div>
</div>
