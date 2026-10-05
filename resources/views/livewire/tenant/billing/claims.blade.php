<?php

use App\Livewire\Concerns\WithTable;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\Tpa;
use App\Services\BillingService;
use App\Support\Sequence;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Insurance Claims')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at', 'claim_amount'];

    #[Url]
    public string $status = '';

    #[Url]
    public string $tpa = '';

    #[Url]
    public ?int $claim = null;

    public bool $showEdit = false;

    public array $form = [];

    public bool $showNew = false;

    public ?string $invoiceId = null;

    public function mount(): void
    {
        if ($this->claim) {
            $this->edit($this->claim);
        }
    }

    public function edit(int $id): void
    {
        $c = InsuranceClaim::findOrFail($id);
        $this->claim = $id;
        $this->form = [
            'status' => $c->status, 'policy_no' => (string) $c->policy_no, 'claim_amount' => (string) $c->claim_amount, 'approved_amount' => (string) $c->approved_amount,
            'settled_amount' => (string) $c->settled_amount, 'submitted_at' => $c->submitted_at?->toDateString() ?? '', 'settled_at' => $c->settled_at?->toDateString() ?? '',
            'rejection_reason' => (string) $c->rejection_reason, 'notes' => (string) $c->notes,
            'settle_account' => (string) \App\Models\BankAccount::active()->where('type', 'bank')->value('id') ?: (string) \App\Models\BankAccount::cash()->id,
        ];
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function save(BillingService $billing): void
    {
        $this->authorize('insurance.manage');
        $this->validate([
            'form.status' => 'required|in:draft,submitted,under_review,approved,partially_approved,rejected,settled',
            'form.policy_no' => 'nullable|string|max:100',
            'form.claim_amount' => 'required|integer|min:0',
            'form.approved_amount' => 'nullable|integer|min:0',
            'form.settled_amount' => 'nullable|integer|min:0',
            'form.settle_account' => ['required', bank_account_exists()],
            'form.submitted_at' => 'nullable|date',
            'form.settled_at' => 'nullable|date',
            'form.rejection_reason' => 'nullable|string|max:255',
            'form.notes' => 'nullable|string|max:1000',
        ], [], ['form.settle_account' => 'settlement account']);
        $c = InsuranceClaim::with('invoice')->findOrFail($this->claim);
        $before = $c->settled_amount;
        $data = array_map(fn ($v) => $v === '' ? null : $v, \Illuminate\Support\Arr::except($this->form, 'settle_account'));
        if ($data['status'] === 'submitted' && ! $data['submitted_at']) {
            $data['submitted_at'] = today()->toDateString();
        }
        if ($data['status'] === 'settled' && ! $data['settled_at']) {
            $data['settled_at'] = today()->toDateString();
        }
        $c->update(array_merge($data, ['approved_amount' => $data['approved_amount'] ?? 0, 'settled_amount' => $data['settled_amount'] ?? 0]));

        // Mirror the insurer's decision on the invoice and record settlement as an insurance payment.
        if ($c->invoice && $c->invoice->status !== 'cancelled') {
            if (in_array($c->status, ['approved', 'partially_approved', 'settled'])) {
                // Until the insurer pays, the approved amount still covers the bill (it was wiped before,
                // so approval made the patient owe everything); money received becomes a payment below.
                $stillCovered = $c->status === 'settled' ? 0 : max(0, (int) $c->approved_amount - (int) $c->settled_amount);
                $c->invoice->update(['insurance_amount' => min($stillCovered, max(0, (int) $c->invoice->total - (int) $c->invoice->paid_amount))]);
                $c->invoice->recalculate();
                $delta = $c->settled_amount - $before;
                $payable = min($delta, max(0, $c->invoice->fresh()->balance));
                if ($payable > 0) {
                    $billing->addPayment($c->invoice->fresh(), $payable, $this->form['settle_account'], $c->claim_no, false, 'TPA settlement', 'insurance');
                }
            } elseif ($c->status === 'rejected') {
                $c->invoice->update(['insurance_amount' => 0]);
                $c->invoice->recalculate();
            }
        }

        $this->showEdit = false;
        $this->claim = null;
        $this->toast('Claim updated.');
    }

    public function newClaim(): void
    {
        $this->authorize('insurance.manage');
        $this->invoiceId = null;
        $this->showNew = true;
    }

    public function createClaim(): void
    {
        $this->authorize('insurance.manage');
        $this->validate(['invoiceId' => ['required', tenant_exists('invoices')]]);
        $inv = Invoice::with('patient')->findOrFail($this->invoiceId);
        if (! $inv->tpa_id) {
            $this->addError('invoiceId', 'This invoice has no insurance company (TPA).');

            return;
        }
        $c = InsuranceClaim::firstOrCreate(['invoice_id' => $inv->id], [
            'claim_no' => Sequence::code('claim', 'CLM'), 'tpa_id' => $inv->tpa_id, 'patient_id' => $inv->patient_id,
            'ipd_admission_id' => $inv->ipd_admission_id, 'policy_no' => $inv->patient->insurance_policy_no,
            'claim_amount' => (float) $inv->insurance_amount ?: (float) $inv->total, 'status' => 'draft', 'created_by' => auth()->id(),
        ]);
        $this->showNew = false;
        $this->edit($c->id);
    }

    public function with(): array
    {
        $query = InsuranceClaim::with(['tpa', 'patient', 'invoice'])
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->tpa, fn ($q) => $q->where('tpa_id', $this->tpa))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('claim_no', 'like', "%{$this->search}%")->orWhere('policy_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        return [
            'claims' => $this->applySort($query)->paginate($this->perPage),
            'tpas' => Tpa::orderBy('name')->pluck('name', 'id'),
            'summary' => InsuranceClaim::selectRaw('status, count(*) c, sum(claim_amount) amount')->groupBy('status')->get()->keyBy('status'),
            'eligibleInvoices' => $this->showNew ? Invoice::with('patient')->whereNotNull('tpa_id')->where('status', '!=', 'cancelled')->doesntHave('claims')->latest()->limit(100)->get()
                ->mapWithKeys(fn ($i) => [$i->id => "{$i->invoice_no} · {$i->patient->full_name} · ".money($i->total)])->all() : [],
            'statuses' => ['draft', 'submitted', 'under_review', 'approved', 'partially_approved', 'rejected', 'settled'],
        ];
    }
}; ?>

<div>
    <x-page-header title="Insurance / TPA Claims" subtitle="Claim tracking" :breadcrumbs="['Invoices' => route('tenant.billing.invoices')]">
        <button class="btn btn-primary btn-sm" wire:click="newClaim"><i class="ri-add-line me-1"></i>New claim</button>
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($statuses as $s)
            <div class="border rounded px-3 py-2 {{ $status === $s ? 'border-primary' : '' }}" role="button" wire:click="$set('status', '{{ $status === $s ? '' : $s }}')">
                <div class="fs-12 text-muted">{{ label($s) }}</div>
                <strong>{{ $summary[$s]->c ?? 0 }}</strong> <span class="fs-12 text-muted">· {{ money($summary[$s]->amount ?? 0) }}</span>
            </div>
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Claim #, policy or patient...">
            <select class="form-select w-auto" wire:model.live="tpa"><option value="">All TPAs</option>@foreach ($tpas as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Claim</th><th>Patient</th><th>TPA</th><th>Invoice</th><x-th field="claim_amount" :sort="$sortField" :dir="$sortDirection" class="text-end">Claimed</x-th><th class="text-end">Approved</th><th class="text-end">Settled</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($claims as $c)
                        <tr wire:key="clm-{{ $c->id }}">
                            <td class="fw-semibold">{{ $c->claim_no }}<div class="fs-12 text-muted">{{ $c->policy_no }}</div></td>
                            <td>{{ $c->patient->full_name }}</td><td>{{ $c->tpa->name }}</td>
                            <td>@if ($c->invoice)<a href="{{ route('tenant.billing.show', $c->invoice) }}" wire:navigate>{{ $c->invoice->invoice_no }}</a>@endif</td>
                            <td class="text-end">{{ money($c->claim_amount) }}</td><td class="text-end">{{ money($c->approved_amount) }}</td><td class="text-end">{{ money($c->settled_amount) }}</td>
                            <td>{{ fmt_date($c->submitted_at) }}</td><td><x-status :value="$c->status" /></td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary" wire:click="edit({{ $c->id }})">Update</button></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="10" message="No claims." icon="ri-shield-cross-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $claims->links() }}</div>
    </div>

    <x-modal wire:model="showEdit" title="Update claim" size="lg">
        <div class="row">
            <x-form.select class="col-md-4" label="Status" model="form.status" :options="collect($statuses)->mapWithKeys(fn ($s) => [$s => label($s)])->all()" :placeholder="false" />
            <x-form.input class="col-md-4" label="Policy no." model="form.policy_no" />
            <x-form.input class="col-md-4" label="Submitted on" model="form.submitted_at" type="date" />
            <x-form.money class="col-md-4" label="Claim amount" model="form.claim_amount" />
            <x-form.money class="col-md-4" label="Approved amount" model="form.approved_amount" />
            <x-form.money class="col-md-4" label="Settled amount" model="form.settled_amount" />
            <x-form.input class="col-md-4" label="Settled on" model="form.settled_at" type="date" />
            <x-form.account class="col-md-8" label="Settlement received in" model="form.settle_account" />
            <x-form.input class="col-12" label="Rejection / deduction reason" model="form.rejection_reason" />
            <x-form.textarea class="col-12" label="Notes" model="form.notes" rows="2" />
        </div>
        <p class="fs-12 text-muted mb-0">Settled amounts are posted to the invoice as “insurance” payments and added to the chosen bank / cash account.</p>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showNew" title="New claim from invoice">
        <x-form.search-select label="Invoice (insured patients)" model="invoiceId" :options="$eligibleInvoices" required />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="createClaim">Create claim</button></x-slot:footer>
    </x-modal>
</div>
