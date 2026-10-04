<?php

use App\Livewire\Concerns\WithTable;
use App\Models\DoctorCommission;
use App\Models\Staff;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Doctor Commissions')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'earned_at';

    protected array $sortable = ['earned_at', 'amount'];

    #[Url]
    public string $doctor = '';

    #[Url]
    public string $status = 'pending';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: today()->startOfMonth()->toDateString();
        $this->to = $this->to ?: today()->toDateString();
    }

    public function markPaid(int $id): void
    {
        $this->authorize('hr.commissions');
        DoctorCommission::where('status', 'pending')->findOrFail($id)->update(['status' => 'paid']);
        $this->toast('Commission marked as paid (outside payroll).');
    }

    public function with(): array
    {
        $filters = fn ($q) => $q
            ->when($this->doctor, fn ($q) => $q->where('staff_id', $this->doctor))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->from, fn ($q) => $q->whereDate('earned_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('earned_at', '<=', $this->to));

        return [
            'rows' => $this->applySort($filters(DoctorCommission::with(['doctor', 'invoiceItem.invoice', 'payroll'])))->paginate($this->perPage),
            'summary' => $filters(DoctorCommission::with('doctor'))->get()->groupBy('staff_id')->map(fn ($g) => ['name' => $g->first()->doctor?->display_name, 'base' => $g->sum('base_amount'), 'amount' => $g->sum('amount'), 'count' => $g->count()])->sortByDesc('amount'),
            'doctors' => Staff::doctors()->orderBy('name')->get()->mapWithKeys(fn ($d) => [$d->id => $d->display_name])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Doctor Commissions" subtitle="Consultation fee splitting" />
    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Summary by doctor</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($summary as $s)
                        <li class="list-group-item"><div class="d-flex justify-content-between"><strong>{{ $s['name'] }}</strong><strong>{{ money($s['amount']) }}</strong></div><small class="text-muted">{{ $s['count'] }} services · on {{ money($s['base']) }} billed</small></li>
                    @empty
                        <li class="list-group-item text-muted text-center py-4">No commissions.</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header d-flex flex-wrap gap-2">
                    <input type="date" class="form-control form-control-sm w-auto" wire:model.live="from">
                    <input type="date" class="form-control form-control-sm w-auto" wire:model.live="to">
                    <select class="form-select form-select-sm w-auto" wire:model.live="doctor"><option value="">All doctors</option>@foreach ($doctors as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
                    <select class="form-select form-select-sm w-auto" wire:model.live="status"><option value="">Any</option><option value="pending">Pending</option><option value="paid">Paid</option></select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead class="table-light"><tr><x-th field="earned_at" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Doctor</th><th>Service</th><th class="text-end">Billed</th><th class="text-end">%</th><x-th field="amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Commission</x-th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($rows as $c)
                                <tr><td>{{ fmt_date($c->earned_at) }}</td><td>{{ $c->doctor?->display_name }}</td><td class="fs-13">{{ $c->description }}<div class="fs-11 text-muted">{{ $c->invoiceItem?->invoice?->invoice_no }}</div></td>
                                    <td class="text-end">{{ money($c->base_amount) }}</td><td class="text-end">{{ (float) $c->percent }}</td><td class="text-end fw-semibold">{{ money($c->amount) }}</td>
                                    <td><x-status :value="$c->status" />@if ($c->payroll)<div class="fs-11 text-muted">Payroll {{ $c->payroll->month }}</div>@endif</td>
                                    <td class="text-end">@if ($c->status === 'pending' && ! $c->payroll_id)<button class="btn btn-sm btn-light-success" x-on:click="$confirm('Mark as paid outside payroll?', () => $wire.markPaid({{ $c->id }}), { color: 'success' })">Paid</button>@endif</td></tr>
                            @empty
                                <x-empty-row :colspan="8" message="No commissions for the filters." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $rows->links() }}</div>
            </div>
        </div>
    </div>
</div>
