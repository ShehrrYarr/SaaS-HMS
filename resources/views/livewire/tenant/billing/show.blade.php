<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BankAccount;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\ServiceCharge;
use App\Services\BillingService;
use App\Support\Sequence;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Invoice')] class extends Component
{
    use Toasts;

    public Invoice $invoice;

    public bool $showPay = false;

    public array $pay = ['amount' => '', 'account' => '', 'reference' => '', 'refund' => false, 'notes' => ''];

    public bool $showItem = false;

    public array $item = [];

    public string $insurance_amount = '';

    public string $cancelReason = '';

    public bool $showCancel = false;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
        $this->insurance_amount = (string) $invoice->insurance_amount;
    }

    public function openPay(bool $refund = false): void
    {
        $this->authorize($refund ? 'billing.cancel' : 'billing.collect');
        $this->pay = ['amount' => (string) ($refund ? $this->invoice->paid_amount : max(0, $this->invoice->balance)), 'account' => (string) BankAccount::cash()->id, 'reference' => '', 'refund' => $refund, 'notes' => ''];
        $this->resetValidation();
        $this->showPay = true;
    }

    public function savePayment(BillingService $billing): void
    {
        $this->authorize($this->pay['refund'] ? 'billing.cancel' : 'billing.collect');
        $this->validate([
            'pay.amount' => 'required|integer|min:1',
            'pay.account' => ['required', bank_account_exists()],
            'pay.reference' => 'nullable|string|max:100',
            'pay.notes' => 'nullable|string|max:200',
        ], [], ['pay.amount' => 'amount', 'pay.account' => 'account']);
        $payment = $billing->addPayment($this->invoice, (int) $this->pay['amount'], $this->pay['account'], $this->pay['reference'] ?: null, (bool) $this->pay['refund'], $this->pay['notes'] ?: null);
        $this->invoice->refresh();
        $this->showPay = false;
        $this->toast(($this->pay['refund'] ? 'Refund ' : 'Payment ').$payment->payment_no.' recorded.');
    }

    public function openItem(): void
    {
        $this->authorize('billing.create');
        $this->item = ['service_id' => '', 'service_type' => 'service', 'description' => '', 'quantity' => 1, 'unit_price' => '', 'discount' => 0, 'tax_percent' => (string) hospital()->tax_rate];
        $this->showItem = true;
    }

    public function updatedItemServiceId($id): void
    {
        if ($s = ServiceCharge::find($id)) {
            $this->item['description'] = $s->name;
            $this->item['unit_price'] = (string) $s->price;
        }
    }

    public function saveItem(BillingService $billing): void
    {
        $this->authorize('billing.create');
        $this->validate([
            'item.service_type' => 'required|string',
            'item.description' => 'required|string|max:255',
            'item.quantity' => 'required|numeric|min:0.01',
            'item.unit_price' => 'required|integer|min:0',
            'item.discount' => 'nullable|integer|min:0',
            'item.tax_percent' => 'nullable|numeric|min:0|max:100',
        ]);
        $billing->addItem($this->invoice, $this->item);
        $this->invoice->refresh();
        $this->showItem = false;
        $this->toast('Item added.');
    }

    public function removeItem(int $id, BillingService $billing): void
    {
        $this->authorize('billing.cancel');
        $billing->removeItem($this->invoice->items()->findOrFail($id));
        $this->invoice->refresh();
    }

    public function saveInsurance(): void
    {
        $this->authorize('insurance.manage');
        $this->validate(['insurance_amount' => 'required|integer|min:0|max:'.$this->invoice->total]);
        $this->invoice->update(['insurance_amount' => $this->insurance_amount]);
        $this->invoice->recalculate();
        $this->toast('Insurance coverage updated.');
    }

    public function createClaim()
    {
        $this->authorize('insurance.manage');
        abort_unless($this->invoice->tpa_id, 422, 'Invoice has no TPA.');
        $claim = InsuranceClaim::firstOrCreate(['invoice_id' => $this->invoice->id], [
            'claim_no' => Sequence::code('claim', 'CLM'),
            'tpa_id' => $this->invoice->tpa_id,
            'patient_id' => $this->invoice->patient_id,
            'ipd_admission_id' => $this->invoice->ipd_admission_id,
            'policy_no' => $this->invoice->patient->insurance_policy_no,
            'claim_amount' => (float) $this->invoice->insurance_amount ?: (float) $this->invoice->total,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return $this->redirect(route('tenant.billing.claims', ['claim' => $claim->id]), navigate: true);
    }

    public function cancelInvoice(BillingService $billing): void
    {
        $this->authorize('billing.cancel');
        $this->validate(['cancelReason' => 'required|string|max:200']);
        $billing->cancel($this->invoice, $this->cancelReason);
        $this->invoice->refresh();
        $this->showCancel = false;
        $this->toast('Invoice cancelled.', 'warning');
    }

    public function with(): array
    {
        return [
            'inv' => $this->invoice->load(['patient', 'items.doctor', 'payments.receiver', 'payments.account', 'tpa', 'admission', 'opdVisit', 'claims', 'creator']),
            'services' => ServiceCharge::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.money($s->price)])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Invoice '.$inv->invoice_no" :subtitle="$inv->patient->full_name" :breadcrumbs="['Invoices' => route('tenant.billing.invoices')]">
        <x-status :value="$inv->status" />
        <a href="{{ route('tenant.billing.pdf', $inv->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line me-1"></i>Print</a>
        @if (! in_array($inv->status, ['paid', 'cancelled']))
            @can('billing.collect')<button class="btn btn-sm btn-success" wire:click="openPay(false)"><i class="ri-money-rupee-circle-line me-1"></i>Collect payment</button>@endcan
        @endif
        @if ($inv->paid_amount > 0 && $inv->status !== 'cancelled')
            @can('billing.cancel')<button class="btn btn-sm btn-light-warning" wire:click="openPay(true)">Refund</button>@endcan
        @endif
        @if ($inv->status !== 'cancelled' && $inv->paid_amount <= 0)
            @can('billing.cancel')<button class="btn btn-sm btn-light-danger" wire:click="$set('showCancel', true)">Cancel</button>@endcan
        @endif
    </x-page-header>

    @if ($inv->status === 'cancelled')
        <div class="alert alert-danger">Cancelled {{ fmt_datetime($inv->cancelled_at) }} — {{ $inv->cancel_reason }}</div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Items</h6>
                    @if (! in_array($inv->status, ['paid', 'cancelled']))
                        @can('billing.create')<button class="btn btn-sm btn-light-primary" wire:click="openItem"><i class="ri-add-line"></i> Add item</button>@endcan
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead class="table-light"><tr><th>Service</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Disc.</th><th class="text-end">Tax</th><th class="text-end">Total</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($inv->items as $i)
                                <tr><td><span class="badge bg-light text-body">{{ strtoupper($i->service_type) }}</span></td><td>{{ $i->description }} @if ($i->doctor)<small class="text-muted d-block">{{ $i->doctor->display_name }}</small>@endif</td>
                                    <td class="text-end">{{ (float) $i->quantity }}</td><td class="text-end">{{ money($i->unit_price) }}</td><td class="text-end">{{ money($i->discount) }}</td><td class="text-end">{{ money($i->tax_amount) }}</td><td class="text-end">{{ money($i->total) }}</td>
                                    <td class="text-end">@if (! in_array($inv->status, ['paid', 'cancelled']))@can('billing.cancel')<button class="btn btn-sm btn-link text-danger p-0" x-on:click="$confirm('Remove this item?', () => $wire.removeItem({{ $i->id }}))"><i class="ri-close-line"></i></button>@endcan @endif</td></tr>
                            @endforeach
                        </tbody>
                        <tfoot class="fs-13">
                            <tr><td colspan="6" class="text-end">Subtotal</td><td class="text-end">{{ money($inv->subtotal) }}</td><td></td></tr>
                            @if ($inv->discount > 0)<tr><td colspan="6" class="text-end">Invoice discount</td><td class="text-end">- {{ money($inv->discount) }}</td><td></td></tr>@endif
                            <tr><td colspan="6" class="text-end">{{ hospital()->tax_label }}</td><td class="text-end">{{ money($inv->tax) }}</td><td></td></tr>
                            <tr class="fw-bold fs-6"><td colspan="6" class="text-end">Total</td><td class="text-end">{{ money($inv->total) }}</td><td></td></tr>
                            @if ($inv->insurance_amount > 0)<tr><td colspan="6" class="text-end">Covered by {{ $inv->tpa?->name ?? 'insurance' }}</td><td class="text-end">- {{ money($inv->insurance_amount) }}</td><td></td></tr>@endif
                            <tr><td colspan="6" class="text-end">Paid</td><td class="text-end">- {{ money($inv->paid_amount) }}</td><td></td></tr>
                            <tr class="fw-bold text-{{ $inv->balance > 0 ? 'danger' : 'success' }}"><td colspan="6" class="text-end">Balance due</td><td class="text-end">{{ money(max(0, $inv->balance)) }}</td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead class="table-light"><tr><th>Receipt</th><th>Date</th><th>Account</th><th>Reference</th><th>By</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                            @forelse ($inv->payments as $p)
                                <tr class="{{ $p->is_refund ? 'table-warning' : '' }}"><td>{{ $p->payment_no }} @if ($p->is_refund)<span class="badge bg-warning">Refund</span>@endif</td><td>{{ fmt_datetime($p->paid_at) }}</td><td>{{ $p->accountLabel() }}</td><td class="fs-12">{{ $p->reference }} {{ $p->notes }}</td><td class="fs-12">{{ $p->receiver?->name }}</td><td class="text-end">{{ $p->is_refund ? '-' : '' }}{{ money($p->amount) }}</td></tr>
                            @empty
                                <x-empty-row :colspan="6" message="No payments yet." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body fs-13">
                    <h6><a href="{{ route('tenant.patients.show', $inv->patient) }}" wire:navigate>{{ $inv->patient->full_name }}</a></h6>
                    <p class="text-muted">{{ $inv->patient->uhid }} · {{ $inv->patient->phone }}</p>
                    <dl class="row mb-0">
                        <dt class="col-5">Invoice date</dt><dd class="col-7">{{ fmt_date($inv->invoice_date) }}</dd>
                        <dt class="col-5">Due date</dt><dd class="col-7">{{ fmt_date($inv->due_date) }}</dd>
                        @if ($inv->admission)<dt class="col-5">Admission</dt><dd class="col-7"><a href="{{ route('tenant.ipd.show', $inv->admission) }}" wire:navigate>{{ $inv->admission->admission_no }}</a></dd>@endif
                        @if ($inv->opdVisit)<dt class="col-5">OPD visit</dt><dd class="col-7">{{ $inv->opdVisit->visit_no }}</dd>@endif
                        <dt class="col-5">Created by</dt><dd class="col-7">{{ $inv->creator?->name }}</dd>
                    </dl>
                    @if ($inv->notes)<p class="mt-2 mb-0 text-muted">{{ $inv->notes }}</p>@endif
                </div>
            </div>

            @if ($inv->tpa_id)
                @can('insurance.manage')
                    <div class="card mb-0">
                        <div class="card-header"><h6 class="card-title mb-0"><i class="ri-shield-cross-line me-1"></i>Insurance · {{ $inv->tpa->name }}</h6></div>
                        <div class="card-body">
                            <div class="input-group mb-2">
                                <span class="input-group-text">{{ currency_symbol() }}</span>
                                <input type="number" step="1" min="0" inputmode="numeric" class="form-control" wire:model="insurance_amount" @disabled($inv->status === 'cancelled')>
                                <button class="btn btn-light-primary" wire:click="saveInsurance">Set cover</button>
                            </div>
                            <p class="fs-12 text-muted">Amount expected from the insurer; the patient pays the rest.</p>
                            @forelse ($inv->claims as $c)
                                <a href="{{ route('tenant.billing.claims', ['claim' => $c->id]) }}" wire:navigate class="d-block">Claim {{ $c->claim_no }} <x-status :value="$c->status" /></a>
                            @empty
                                <button class="btn btn-sm btn-info w-100" wire:click="createClaim">Create TPA claim</button>
                            @endforelse
                        </div>
                    </div>
                @endcan
            @endif
        </div>
    </div>

    <x-modal wire:model="showPay" :title="($pay['refund'] ?? false) ? 'Refund' : 'Collect payment'">
        <div class="row">
            <x-form.money class="col-md-6" label="Amount" model="pay.amount" required />
            <x-form.account class="col-md-6" :label="($pay['refund'] ?? false) ? 'Paid from' : 'Received in'" model="pay.account" />
            <x-form.input class="col-12" label="Reference" model="pay.reference" placeholder="Transaction ID, cheque no. or card slip (optional)" />
            <x-form.input class="col-12" label="Notes" model="pay.notes" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn {{ ($pay['refund'] ?? false) ? 'btn-warning' : 'btn-success' }}" wire:click="savePayment">{{ ($pay['refund'] ?? false) ? 'Refund' : 'Record payment' }}</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showItem" title="Add item">
        <x-form.select label="From service list" model="item.service_id" :options="$services" placeholder="Custom" live />
        <div class="row">
            <x-form.select class="col-md-5" label="Type" model="item.service_type" :options="['service' => 'Service', 'opd' => 'OPD', 'ipd' => 'IPD', 'pharmacy' => 'Pharmacy', 'lab' => 'Lab', 'radiology' => 'Radiology', 'ot' => 'OT', 'bloodbank' => 'Blood bank', 'other' => 'Other']" :placeholder="false" />
            <x-form.input class="col-md-7" label="Description" model="item.description" required />
            <x-form.input class="col-md-3" label="Qty" model="item.quantity" type="number" step="0.5" />
            <x-form.money class="col-md-3" label="Price" model="item.unit_price" />
            <x-form.money class="col-md-3" label="Discount" model="item.discount" />
            <x-form.input class="col-md-3" label="Tax %" model="item.tax_percent" type="number" step="0.01" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveItem">Add</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showCancel" title="Cancel invoice">
        <x-form.textarea label="Reason" model="cancelReason" rows="2" required />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Back</button><button class="btn btn-danger" wire:click="cancelInvoice">Cancel invoice</button></x-slot:footer>
    </x-modal>
</div>
