<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Payroll;
use App\Services\PayrollService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Payroll')] class extends Component
{
    use Toasts;

    #[Url]
    public string $month = '';

    public string $payMethod = 'bank_transfer';

    public function mount(): void
    {
        $this->month = $this->month ?: today()->format('Y-m');
    }

    public function generate(PayrollService $payroll): void
    {
        $this->authorize('hr.payroll');
        $this->validate(['month' => 'required|date_format:Y-m']);
        $n = $payroll->generate($this->month);
        $this->toast("Payroll calculated for {$n} staff (drafts recalculated; approved/paid untouched).");
    }

    public function approve(int $id, PayrollService $payroll): void
    {
        $this->authorize('hr.payroll');
        $payroll->approve(Payroll::findOrFail($id));
    }

    public function approveAll(PayrollService $payroll): void
    {
        $this->authorize('hr.payroll');
        Payroll::where('month', $this->month)->where('status', 'draft')->get()->each(fn ($p) => $payroll->approve($p));
        $this->toast('All draft payroll approved.');
    }

    public function pay(int $id, PayrollService $payroll): void
    {
        $this->authorize('hr.payroll');
        $payroll->pay(Payroll::findOrFail($id), $this->payMethod);
        $this->toast('Marked as paid.');
    }

    public function payAll(PayrollService $payroll): void
    {
        $this->authorize('hr.payroll');
        Payroll::where('month', $this->month)->where('status', 'approved')->get()->each(fn ($p) => $payroll->pay($p, $this->payMethod));
        $this->toast('Approved payroll marked as paid.');
    }

    public function with(): array
    {
        $rows = Payroll::with('staff.department')->where('month', $this->month)->get()->sortBy('staff.name');

        return [
            'rows' => $rows,
            'totals' => ['gross' => $rows->sum(fn ($r) => $r->basic + $r->allowances + $r->commission), 'deductions' => $rows->sum(fn ($r) => $r->deductions + $r->absence_deduction), 'net' => $rows->sum('net_pay'), 'paid' => $rows->where('status', 'paid')->sum('net_pay')],
        ];
    }
}; ?>

<div>
    <x-page-header title="Payroll" :subtitle="\Carbon\Carbon::parse($month.'-01')->format('F Y')">
        <input type="month" class="form-control form-control-sm w-auto" wire:model.live="month">
        <button class="btn btn-primary btn-sm" x-on:click="$confirm('Calculate payroll for this month? Draft rows are recalculated.', () => $wire.generate(), { color: 'primary', confirmText: 'Calculate' })"><i class="ri-calculator-line me-1"></i>Generate / recalculate</button>
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-md-3"><x-stat-card title="Gross" :value="money($totals['gross'])" icon="ri-money-dollar-box-line" color="primary" /></div>
        <div class="col-md-3"><x-stat-card title="Deductions" :value="money($totals['deductions'])" icon="ri-subtract-line" color="danger" /></div>
        <div class="col-md-3"><x-stat-card title="Net payable" :value="money($totals['net'])" icon="ri-wallet-3-line" color="success" /></div>
        <div class="col-md-3"><x-stat-card title="Paid" :value="money($totals['paid'])" icon="ri-checkbox-circle-line" color="info" /></div>
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-end align-items-center">
            <select class="form-select form-select-sm w-auto" wire:model="payMethod"><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option><option value="cheque">Cheque</option></select>
            <button class="btn btn-sm btn-light-success" wire:click="approveAll">Approve all drafts</button>
            <button class="btn btn-sm btn-success" x-on:click="$confirm('Mark all approved payroll as paid?', () => $wire.payAll(), { color: 'success' })">Pay all approved</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hms align-middle mb-0">
                <thead class="table-light"><tr><th>Staff</th><th class="text-center">Days</th><th class="text-end">Basic</th><th class="text-end">Allow.</th><th class="text-end">Commission</th><th class="text-end">Deductions</th><th class="text-end">Absence</th><th class="text-end">Net pay</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $p)
                        <tr wire:key="pr-{{ $p->id }}">
                            <td><strong>{{ $p->staff->display_name }}</strong><div class="fs-12 text-muted">{{ $p->staff->employee_code }} · {{ $p->staff->department?->name }}</div></td>
                            <td class="text-center">{{ $p->present_days }}/{{ $p->working_days }}</td>
                            <td class="text-end">{{ money($p->basic) }}</td><td class="text-end">{{ money($p->allowances) }}</td><td class="text-end">{{ money($p->commission) }}</td>
                            <td class="text-end">{{ money($p->deductions) }}</td><td class="text-end text-danger">{{ money($p->absence_deduction) }}</td>
                            <td class="text-end fw-bold">{{ money($p->net_pay) }}</td>
                            <td><x-status :value="$p->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tenant.hr.payslip', $p->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm" title="Payslip"><i class="ri-file-pdf-2-line"></i></a>
                                @if ($p->status === 'draft')<button class="btn btn-sm btn-light-success" wire:click="approve({{ $p->id }})">Approve</button>@endif
                                @if ($p->status === 'approved')<button class="btn btn-sm btn-success" wire:click="pay({{ $p->id }})">Pay</button>@endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="10" message="No payroll for this month yet — click Generate." icon="ri-calculator-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
