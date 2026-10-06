<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Models\Patient;
use App\Models\ServiceCharge;
use App\Services\BillingService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('New Invoice')] class extends Component
{
    use SearchesPatients;

    public ?string $patient_id = null;

    public ?string $patientLabel = null;

    public array $items = [];

    public string $discount = '0';

    public string $notes = '';

    public string $due_date = '';

    public string $pay_amount = '';

    public string $pay_account = '';

    public string $pay_reference = '';

    public function mount(): void
    {
        $this->pay_account = (string) \App\Models\BankAccount::cash()->id;
        if ($id = request()->integer('patient')) {
            $this->patient_id = (string) $id;
            $this->patientLabel = $this->patientLabel($id);
        }
        $this->due_date = today()->toDateString();
        $this->addItem();
    }

    public function addItem(?int $serviceId = null): void
    {
        $s = $serviceId ? ServiceCharge::find($serviceId) : null;
        $this->items[] = [
            'service_type' => 'service', 'description' => $s?->name ?? '', 'quantity' => 1,
            'unit_price' => $s ? (string) $s->price : '', 'discount' => 0,
            'tax_percent' => (string) ($s?->tax_percent > 0 ? $s->tax_percent : hospital()->tax_rate),
        ];
    }

    public function addService($serviceId): void
    {
        if (! $serviceId) {
            return;
        }
        if (count($this->items) === 1 && $this->items[0]['description'] === '') {
            $this->items = [];
        }
        $this->addItem((int) $serviceId);
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    protected function totals(): array
    {
        $sub = 0;
        $tax = 0;
        $disc = 0;
        foreach ($this->items as $i) {
            $line = rupees((float) ($i['quantity'] ?: 0) * (float) ($i['unit_price'] ?: 0));
            $d = rupees($i['discount'] ?: 0);
            $sub += $line;
            $disc += $d;
            $tax += rupees(max(0, $line - $d) * (float) ($i['tax_percent'] ?: 0) / 100);
        }
        $total = max(0, $sub - $disc - rupees($this->discount) + $tax);

        return ['subtotal' => $sub, 'item_discount' => $disc, 'tax' => $tax, 'total' => $total];
    }

    public function save(BillingService $billing)
    {
        $this->authorize('billing.create');
        $this->validate([
            'patient_id' => ['required', tenant_exists('patients')],
            'items' => 'required|array|min:1',
            'items.*.service_type' => 'required|in:opd,ipd,pharmacy,lab,radiology,ot,bloodbank,service,other',
            'items.*.description' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|integer|min:0',
            'items.*.discount' => 'nullable|integer|min:0',
            'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',
            'discount' => 'nullable|integer|min:0',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'pay_amount' => 'nullable|integer|min:0',
            'pay_account' => ['required_with:pay_amount', 'nullable', bank_account_exists()],
            'pay_reference' => 'nullable|string|max:100',
        ], [], ['patient_id' => 'patient', 'items.*.description' => 'description', 'items.*.unit_price' => 'price', 'pay_account' => 'account']);

        $invoice = $billing->createInvoice(Patient::findOrFail($this->patient_id), $this->items, [
            'discount' => rupees($this->discount),
            'due_date' => $this->due_date ?: null,
            'notes' => $this->notes ?: null,
        ]);

        if ((int) $this->pay_amount > 0 && $invoice->balance > 0) {
            $billing->addPayment($invoice, min((int) $this->pay_amount, $invoice->balance), $this->pay_account, $this->pay_reference ?: null);
        }

        session()->flash('success', "Invoice {$invoice->invoice_no} created.");

        return $this->redirect(route('tenant.billing.show', $invoice), navigate: true);
    }

    public function with(): array
    {
        return [
            'services' => ServiceCharge::where('is_active', true)->orderBy('category')->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.money($s->price)])->all(),
            'totals' => $this->totals(),
            'types' => ['service' => 'Service', 'opd' => 'OPD', 'ipd' => 'IPD', 'pharmacy' => 'Pharmacy', 'lab' => 'Lab', 'radiology' => 'Radiology', 'ot' => 'OT', 'bloodbank' => 'Blood bank', 'other' => 'Other'],
        ];
    }
}; ?>

<div>
    <x-page-header title="New Invoice" subtitle="Multi-service billing" :breadcrumbs="['Invoices' => route('tenant.billing.invoices')]" />

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-body">
                    <div class="row">
                        <x-form.search-select class="col-md-7" label="Patient" model="patient_id" search="searchPatients" :selected-label="$patientLabel" required />
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Add from service list</label>
                            <select class="form-select" x-on:change="$wire.addService($event.target.value); $event.target.value = ''">
                                <option value="">Choose a service…</option>
                                @foreach ($services as $id => $l)<option value="{{ $id }}">{{ $l }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th style="width: 120px;">Type</th><th>Description</th><th style="width: 80px;">Qty</th><th style="width: 110px;">Price</th><th style="width: 100px;">Discount</th><th style="width: 80px;">Tax %</th><th class="text-end">Total</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($items as $i => $item)
                                    @php $line = max(0, rupees((float) ($item['quantity'] ?: 0) * (float) ($item['unit_price'] ?: 0)) - rupees($item['discount'] ?: 0)); @endphp
                                    <tr wire:key="ii-{{ $i }}">
                                        <td><select class="form-select form-select-sm" wire:model="items.{{ $i }}.service_type">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
                                        <td><input type="text" class="form-control form-control-sm @error('items.'.$i.'.description') is-invalid @enderror" wire:model="items.{{ $i }}.description"></td>
                                        <td><input type="number" step="0.5" class="form-control form-control-sm" wire:model.live="items.{{ $i }}.quantity"></td>
                                        <td><input type="number" step="1" min="0" inputmode="numeric" class="form-control form-control-sm @error('items.'.$i.'.unit_price') is-invalid @enderror" wire:model.live="items.{{ $i }}.unit_price"></td>
                                        <td><input type="number" step="1" min="0" inputmode="numeric" class="form-control form-control-sm @error('items.'.$i.'.discount') is-invalid @enderror" wire:model.live="items.{{ $i }}.discount"></td>
                                        <td><input type="number" step="0.01" class="form-control form-control-sm" wire:model.live="items.{{ $i }}.tax_percent"></td>
                                        <td class="text-end">{{ money($line + rupees($line * (float) ($item['tax_percent'] ?: 0) / 100)) }}</td>
                                        <td><button title="Remove" aria-label="Remove" class="btn btn-sm btn-link text-danger" wire:click="removeItem({{ $i }})"><i class="ri-close-line"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-sm btn-light-primary" wire:click="addItem"><i class="ri-add-line"></i> Custom line</button>
                    @error('items')<div class="text-danger mt-2">{{ $message }}</div>@enderror
                    <x-form.textarea class="mt-3" label="Notes" model="notes" rows="2" />
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Summary</h6></div>
                <div class="card-body">
                    <div class="d-flex justify-content-between"><span>Subtotal</span><span>{{ money($totals['subtotal']) }}</span></div>
                    <div class="d-flex justify-content-between"><span>Line discounts</span><span>- {{ money($totals['item_discount']) }}</span></div>
                    <div class="d-flex justify-content-between align-items-center my-1"><span>Invoice discount</span><input type="number" step="1" min="0" inputmode="numeric" class="form-control form-control-sm text-end" style="width: 110px;" wire:model.live="discount"></div>
                    <div class="d-flex justify-content-between"><span>{{ hospital()->tax_label }}</span><span>{{ money($totals['tax']) }}</span></div>
                    <div class="d-flex justify-content-between fs-4 fw-bold border-top pt-2 mt-2"><span>Total</span><span>{{ money($totals['total']) }}</span></div>
                    <x-form.input class="mt-3" label="Due date" model="due_date" type="date" />
                    <hr>
                    <h6 class="mb-2">Collect payment now <small class="text-muted fw-normal">(optional)</small></h6>
                    <div class="row g-2">
                        <x-form.money class="col-6" model="pay_amount" placeholder="Amount" />
                        <x-form.account class="col-6" model="pay_account" :label="null" />
                        <x-form.input class="col-12" model="pay_reference" placeholder="Reference (optional)" />
                    </div>
                </div>
            </div>
            <button class="btn btn-primary w-100" wire:click="save" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Create invoice</button>
        </div>
    </div>
</div>
