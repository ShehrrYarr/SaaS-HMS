<?php

use App\Livewire\Concerns\SearchesPatients;
use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\IpdAdmission;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Prescription;
use App\Services\PharmacyService;
use App\Support\AllergyCheck;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Pharmacy POS')] class extends Component
{
    use SearchesPatients, Toasts;

    public string $q = '';

    public string $barcode = '';

    /** @var array<int, array{medicine_id:int, name:string, price:int, qty:int, stock:int, tax:float}> */
    public array $cart = [];

    public ?string $patient_id = null;

    public ?string $patientLabel = null;

    public string $customer_name = '';

    public string $customer_phone = '';

    public ?int $prescription_id = null;

    public ?int $admission_id = null;

    /** Bank / cash account id the sale is paid into, or "ipd_credit" to post it to the IPD bill. */
    public string $payment_method = '';

    public string $discount = '0';

    public string $tendered = '';

    /** The pharmacist ticked "I have checked the allergy warning". */
    public bool $allergyReviewed = false;

    public function mount(): void
    {
        $this->payment_method = (string) BankAccount::cash()->id;
        if ($rxId = request()->integer('prescription')) {
            $this->loadPrescription($rxId);
        }
        if ($admissionId = request()->integer('admission')) {
            $admission = IpdAdmission::where('status', 'admitted')->findOrFail($admissionId);
            $this->admission_id = $admission->id;
            $this->patient_id = (string) $admission->patient_id;
            $this->patientLabel = $this->patientLabel($admission->patient_id);
            $this->payment_method = 'ipd_credit';
        }
    }

    protected function loadPrescription(int $id): void
    {
        $rx = Prescription::with('items.medicine', 'patient')->whereIn('status', ['issued', 'partially_dispensed'])->findOrFail($id);
        $this->prescription_id = $rx->id;
        $this->patient_id = (string) $rx->patient_id;
        $this->patientLabel = $this->patientLabel($rx->patient_id);
        if ($admission = $rx->patient->currentAdmission) {
            $this->admission_id = $admission->id;
        }
        foreach ($rx->items as $item) {
            if ($item->medicine_id && $item->pending_qty > 0) {
                $this->add($item->medicine_id, $item->pending_qty);
            }
        }
    }

    public function add(int $medicineId, int $qty = 1): void
    {
        $m = Medicine::withStock()->where('is_active', true)->find($medicineId);
        if (! $m) {
            return;
        }
        $stock = (int) $m->stock;
        $existing = $this->cart[$m->id]['qty'] ?? 0;
        if ($stock <= 0) {
            $this->toast("{$m->label} is out of stock.", 'error');

            return;
        }
        $price = rupees($m->sellableBatches()->value('sale_price') ?: $m->sale_price);
        $this->cart[$m->id] = [
            'medicine_id' => $m->id, 'name' => $m->label, 'price' => $price,
            'qty' => min($stock, $existing + $qty), 'stock' => $stock, 'tax' => (float) $m->tax_percent, 'rx' => $m->requires_prescription,
        ];
        $this->q = '';
    }

    public function scan(): void
    {
        $code = trim($this->barcode);
        $this->barcode = '';
        if ($code === '') {
            return;
        }
        $m = Medicine::where('barcode', $code)->first() ?? Medicine::search($code)->first();
        $m ? $this->add($m->id) : $this->toast("No medicine for code {$code}.", 'error');
    }

    public function updatedCart($value, $key): void
    {
        [$id, $field] = explode('.', $key);
        if ($field === 'qty') {
            $line = &$this->cart[$id];
            $line['qty'] = max(1, min((int) $value, (int) $line['stock']));
        }
    }

    public function remove(int $id): void
    {
        unset($this->cart[$id]);
    }

    public function clear(): void
    {
        $this->reset('cart', 'patient_id', 'patientLabel', 'customer_name', 'customer_phone', 'prescription_id', 'admission_id', 'discount', 'tendered', 'allergyReviewed');
        $this->payment_method = (string) BankAccount::cash()->id;
    }

    public function updatedPatientId($id): void
    {
        $this->allergyReviewed = false;
        $p = Patient::with('currentAdmission')->find($id);
        $this->admission_id = $p?->currentAdmission?->id;
        if (! $this->admission_id && $this->payment_method === 'ipd_credit') {
            $this->payment_method = (string) BankAccount::cash()->id;
        }
    }

    /** Cart line => recorded allergies the medicine may trigger, for the selected patient. */
    protected function allergyWarnings(): array
    {
        $patient = $this->patient_id ? Patient::with('allergies')->find($this->patient_id) : null;
        if (! $patient || $patient->allergies->isEmpty() || ! $this->cart) {
            return [];
        }
        $medicines = Medicine::whereIn('id', array_keys($this->cart))->get()->keyBy('id');
        $warnings = [];
        foreach ($this->cart as $id => $line) {
            $hits = AllergyCheck::conflicts($patient, $line['name'], $medicines->get($id));
            if ($hits->isNotEmpty()) {
                $warnings[$id] = AllergyCheck::describe($hits);
            }
        }

        return $warnings;
    }

    protected function totals(): array
    {
        $subtotal = 0;
        $tax = 0;
        foreach ($this->cart as $line) {
            $gross = $line['price'] * $line['qty'];
            $subtotal += $gross;
            $tax += rupees($gross * $line['tax'] / 100);
        }
        $discount = min(rupees($this->discount), $subtotal);
        $total = $subtotal - $discount + $tax;

        return compact('subtotal', 'tax', 'discount', 'total') + ['change' => max(0, rupees($this->tendered) - $total)];
    }

    public function checkout(PharmacyService $pharmacy): void
    {
        $this->authorize('pharmacy.sell');
        $this->validate([
            'cart' => 'required|array|min:1',
            'payment_method' => ['required', $this->payment_method === 'ipd_credit' ? 'in:ipd_credit' : bank_account_exists()],
            'discount' => 'nullable|integer|min:0',
            'patient_id' => ['nullable', tenant_exists('patients')],
            'customer_name' => 'nullable|string|max:120',
        ], ['cart.required' => 'Add at least one medicine.']);

        if ($this->payment_method === 'ipd_credit' && ! $this->admission_id) {
            $this->addError('payment_method', 'IPD credit requires an admitted patient.');

            return;
        }
        $warnings = $this->allergyWarnings();
        if ($warnings && ! $this->allergyReviewed) {
            $this->addError('allergy', 'A medicine in the cart may trigger a recorded allergy. Check it, then tick the box to continue.');

            return;
        }
        $rxRequired = collect($this->cart)->contains(fn ($l) => $l['rx']);
        if ($rxRequired && ! $this->prescription_id && ! $this->admission_id && ! $this->patient_id) {
            $this->addError('cart', 'Prescription-only medicines in cart: select the patient / prescription.');

            return;
        }

        $totals = $this->totals();
        $sale = $pharmacy->sell(
            collect($this->cart)->map(fn ($l) => ['medicine_id' => $l['medicine_id'], 'quantity' => $l['qty'], 'unit_price' => $l['price']])->values()->all(),
            [
                'patient_id' => $this->patient_id ?: null,
                'customer_name' => $this->customer_name ?: null,
                'customer_phone' => $this->customer_phone ?: null,
                'prescription_id' => $this->prescription_id,
                'ipd_admission_id' => $this->admission_id,
                'payment_method' => $this->payment_method === 'ipd_credit' ? 'ipd_credit' : null,
                'bank_account_id' => $this->payment_method === 'ipd_credit' ? null : $this->payment_method,
                'discount' => $totals['discount'],
            ]
        );

        if ($warnings) {
            AuditLog::record('allergy_override', $sale, [], ['allergies' => array_values($warnings)], 'Medicine sold despite an allergy warning');
        }

        $this->clear();
        // The print prompt replaces any toast, so it carries the sale summary itself.
        $this->dispatch('print', url: route('tenant.pharmacy.receipt', $sale->id), title: "Sale {$sale->sale_no} completed · ".money($sale->total));
    }

    public function with(): array
    {
        $term = trim($this->q);

        return [
            'results' => Medicine::withStock()->where('is_active', true)->search($term)->orderBy('name')->limit($term === '' ? 12 : 24)->get(),
            'totals' => $this->totals(),
            'allergyWarnings' => $this->allergyWarnings(),
            'prescription' => $this->prescription_id ? Prescription::with('doctor')->find($this->prescription_id) : null,
            'admission' => $this->admission_id ? IpdAdmission::with('bed.ward')->find($this->admission_id) : null,
            'accounts' => BankAccount::active()->ordered()->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Pharmacy POS" subtitle="Billing counter">
        @can('pharmacy.dispense')<a href="{{ route('tenant.pharmacy.dispense') }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-file-list-3-line me-1"></i>Prescription queue</a>@endcan
        <a href="{{ route('tenant.pharmacy.sales') }}" wire:navigate class="btn btn-light btn-sm"><i class="ri-history-line me-1"></i>Sales</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex flex-wrap gap-2">
                    <form wire:submit="scan" class="flex-grow-1" style="min-width: 220px;">
                        <div class="form-icon">
                            <input type="text" class="form-control form-control-icon" placeholder="Scan barcode + Enter" wire:model="barcode" autofocus x-hotkey-focus="F2">
                            <i class="ri-barcode-line text-muted"></i>
                        </div>
                    </form>
                    <div class="form-icon flex-grow-1" style="min-width: 220px;">
                        <input type="search" class="form-control form-control-icon" placeholder="Search name / generic..." wire:model.live.debounce.300ms="q">
                        <i class="ri-search-2-line text-muted"></i>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        @forelse ($results as $m)
                            <div class="col-sm-6 col-lg-4" wire:key="pm-{{ $m->id }}">
                                <div class="border rounded p-2 h-100 pos-product {{ (int) $m->stock <= 0 ? 'opacity-50' : '' }}" wire:click="add({{ $m->id }})">
                                    <div class="fw-semibold fs-13 text-truncate">{{ $m->label }}</div>
                                    <div class="fs-12 text-muted text-truncate">{{ $m->generic_name }} · {{ label($m->form) }}</div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <span class="text-primary fw-semibold">{{ money($m->sale_price) }}</span>
                                        <span class="badge {{ (int) $m->stock <= $m->reorder_level ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' }}">{{ (int) $m->stock }} left</span>
                                    </div>
                                    @if ($m->requires_prescription)<span class="badge bg-warning-subtle text-warning fs-10">Rx</span>@endif
                                </div>
                            </div>
                        @empty
                            <div class="col-12 text-muted text-center py-4">No medicines found.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><i class="ri-shopping-cart-2-line me-1"></i>Cart ({{ count($cart) }})</h5>
                    @if ($cart)<button class="btn btn-sm btn-light-danger" wire:click="clear">Clear</button>@endif
                </div>
                <div class="card-body">
                    @if ($prescription)
                        <div class="alert alert-info py-2 fs-13 mb-2"><i class="ri-file-list-3-line"></i> Dispensing {{ $prescription->prescription_no }} · {{ $prescription->doctor->display_name }}</div>
                    @endif
                    @if ($admission)
                        <div class="alert alert-warning py-2 fs-13 mb-2"><i class="ri-hotel-bed-line"></i> In-patient {{ $admission->admission_no }} · {{ $admission->bed?->label }}</div>
                    @endif

                    <x-form.search-select label="Patient (optional)" model="patient_id" search="searchPatients" :selected-label="$patientLabel" placeholder="Walk-in customer" live class="mb-2" />
                    @unless ($patient_id)
                        <div class="row g-2 mb-3">
                            <div class="col-7"><input type="text" class="form-control form-control-sm" placeholder="Customer name" wire:model="customer_name"></div>
                            <div class="col-5"><input type="text" class="form-control form-control-sm" placeholder="Phone" wire:model="customer_phone"></div>
                        </div>
                    @endunless

                    <div class="pos-cart mb-3">
                        @forelse ($cart as $id => $line)
                            <div class="d-flex align-items-center gap-2 border-bottom py-2" wire:key="cl-{{ $id }}">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fs-13 fw-semibold text-truncate">{{ $line['name'] }} @if ($line['rx'])<span class="badge bg-warning-subtle text-warning">Rx</span>@endif</div>
                                    <div class="fs-12 text-muted">{{ money($line['price']) }} × {{ $line['qty'] }} @if ($line['tax'] > 0)· tax {{ $line['tax'] }}%@endif</div>
                                </div>
                                <input type="number" min="1" max="{{ $line['stock'] }}" class="form-control form-control-sm" style="width: 70px;" wire:model.live="cart.{{ $id }}.qty">
                                <span class="fw-semibold text-nowrap" style="width: 80px; text-align: right;">{{ money($line['price'] * $line['qty']) }}</span>
                                <button class="btn btn-sm btn-link text-danger p-0" wire:click="remove({{ $id }})" title="Remove"><i class="ri-delete-bin-line"></i></button>
                            </div>
                            @isset($allergyWarnings[$id])
                                <div class="alert alert-danger py-1 px-2 fs-12 mt-1 mb-0" role="alert"><i class="ri-alarm-warning-line me-1"></i>Allergy: {{ $allergyWarnings[$id] }}. This medicine may cause a reaction.</div>
                            @endisset
                        @empty
                            <div class="text-center text-muted py-4"><i class="ri-shopping-cart-line fs-1 d-block opacity-50"></i>Scan or click a medicine to add it.</div>
                        @endforelse
                    </div>
                    @error('cart')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

                    <div class="d-flex justify-content-between"><span>Subtotal</span><span>{{ money($totals['subtotal']) }}</span></div>
                    <div class="d-flex justify-content-between align-items-center my-1"><span>Discount</span><input type="number" step="1" min="0" inputmode="numeric" class="form-control form-control-sm text-end @error('discount') is-invalid @enderror" style="width: 110px;" wire:model.live="discount"></div>
                    @error('discount')<div class="text-danger fs-12 text-end mb-1">{{ $message }}</div>@enderror
                    <div class="d-flex justify-content-between"><span>Tax</span><span>{{ money($totals['tax']) }}</span></div>
                    <div class="d-flex justify-content-between fs-4 fw-bold border-top pt-2 mt-2"><span>Total</span><span>{{ money($totals['total']) }}</span></div>

                    <div class="fs-12 text-muted mt-3 mb-1">Received in</div>
                    <div class="d-flex flex-wrap gap-1 mb-3" role="group">
                        @foreach ($accounts->mapWithKeys(fn ($a) => [$a->id => $a->label])->all() + ($admission_id ? ['ipd_credit' => 'IPD bill (credit)'] : []) as $k => $l)
                            <input type="radio" class="btn-check" id="pm-{{ $k }}" value="{{ $k }}" wire:model.live="payment_method">
                            <label class="btn btn-outline-primary btn-sm flex-fill" for="pm-{{ $k }}">{{ $l }}</label>
                        @endforeach
                    </div>
                    @error('payment_method')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
                    @if ($accounts->firstWhere('id', (int) $payment_method)?->isCash())
                        <div class="d-flex gap-2 align-items-center mb-3">
                            <input type="number" step="1" min="0" inputmode="numeric" class="form-control" placeholder="Amount tendered" wire:model.live="tendered">
                            <span class="text-nowrap">Change: <strong>{{ money($totals['change']) }}</strong></span>
                        </div>
                    @endif
                    @if ($allergyWarnings)
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="allergyReviewed" wire:model="allergyReviewed">
                            <label class="form-check-label fs-13 text-danger" for="allergyReviewed">I have checked the allergy warning with the patient or doctor.</label>
                        </div>
                        @error('allergy')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
                    @endif
                    <button class="btn btn-success w-100 btn-lg" wire:click="checkout" wire:loading.attr="disabled" @disabled(empty($cart))>
                        <span wire:loading.remove wire:target="checkout"><i class="ri-secure-payment-line me-1"></i>Complete sale &amp; print</span>
                        <span wire:loading wire:target="checkout">Processing…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
