<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BankAccount;
use App\Models\Bed;
use App\Models\IpdAdmission;
use App\Models\LabTest;
use App\Models\RadiologyTest;
use App\Models\ServiceCharge;
use App\Models\Staff;
use App\Models\Ward;
use App\Services\DiagnosticsService;
use App\Services\IpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Admission')] class extends Component
{
    use Toasts;

    public IpdAdmission $admission;

    #[Url]
    public string $tab = 'overview';

    public array $charge = ['service' => '', 'category' => 'procedure', 'description' => '', 'quantity' => 1, 'unit_price' => '', 'doctor_id' => ''];

    public bool $showTransfer = false;

    public ?string $newBed = null;

    public string $transferReason = '';

    public array $discharge = ['discharge_type' => 'normal', 'discharge_summary' => '', 'discharge_condition' => '', 'discharge_instructions' => '', 'follow_up_date' => ''];

    public array $labTests = [];

    public array $imaging = [];

    public string $refundAccount = '';

    public function mount(IpdAdmission $admission): void
    {
        $this->admission = $admission;
        $this->refundAccount = (string) BankAccount::cash()->id;
    }

    public function updatedChargeService($id): void
    {
        if ($s = ServiceCharge::find($id)) {
            $this->charge['description'] = $s->name;
            $this->charge['unit_price'] = (string) $s->price;
            $this->charge['category'] = in_array($s->category, ['nursing', 'consultation', 'procedure', 'consumable']) ? ($s->category === 'consultation' ? 'doctor_visit' : $s->category) : 'other';
        }
    }

    public function addCharge(IpdService $ipd): void
    {
        $this->authorize('ipd.charges');
        $this->validate([
            'charge.category' => 'required|in:nursing,doctor_visit,procedure,consumable,medicine,other',
            'charge.description' => 'required|string|max:200',
            'charge.quantity' => 'required|numeric|min:0.01',
            'charge.unit_price' => 'required|integer|min:0',
            'charge.doctor_id' => ['nullable', tenant_exists('staff')],
        ]);
        $ipd->addCharge($this->admission, array_merge($this->charge, ['doctor_id' => $this->charge['doctor_id'] ?: null]));
        $this->charge = ['service' => '', 'category' => 'procedure', 'description' => '', 'quantity' => 1, 'unit_price' => '', 'doctor_id' => ''];
        $this->toast('Charge added.');
    }

    public function removeCharge(int $id): void
    {
        $this->authorize('ipd.charges');
        $this->admission->charges()->where('billed', false)->findOrFail($id)->delete();
    }

    public function transfer(IpdService $ipd): void
    {
        $this->authorize('ipd.transfer');
        $this->validate(['newBed' => ['required', tenant_exists('beds')], 'transferReason' => 'nullable|string|max:100']);
        $ipd->transfer($this->admission, (int) $this->newBed, $this->transferReason ?: 'transfer');
        $this->admission->refresh();
        $this->showTransfer = false;
        $this->reset('newBed', 'transferReason');
        $this->toast('Patient transferred to '.$this->admission->bed->label);
    }

    public function orderLab(DiagnosticsService $d): void
    {
        $this->authorize('lab.order');
        $this->validate(['labTests' => 'required|array|min:1']);
        $order = $d->orderLab($this->admission->patient, $this->labTests, $this->admission->doctor, $this->admission);
        $this->labTests = [];
        $this->toast("Lab order {$order->order_no} placed (charged to admission).");
    }

    public function orderImaging(DiagnosticsService $d): void
    {
        $this->authorize('radiology.order');
        $this->validate(['imaging' => 'required|array|min:1']);
        $d->orderRadiology($this->admission->patient, $this->imaging, $this->admission->doctor, $this->admission);
        $this->imaging = [];
        $this->toast('Imaging ordered (charged to admission).');
    }

    public function dischargePatient(IpdService $ipd): void
    {
        $this->authorize('ipd.discharge');
        $data = $this->validate([
            'discharge.discharge_type' => 'required|in:normal,lama,referred,expired,absconded',
            'discharge.discharge_summary' => 'required|string|max:10000',
            'discharge.discharge_condition' => 'nullable|string|max:2000',
            'discharge.discharge_instructions' => 'nullable|string|max:5000',
            'discharge.follow_up_date' => 'nullable|date|after:today',
        ], [], ['discharge.discharge_summary' => 'discharge summary', 'discharge.follow_up_date' => 'follow-up date'])['discharge'];

        $invoice = $ipd->discharge($this->admission, array_map(fn ($v) => $v === '' ? null : $v, $data));
        $this->admission->refresh();
        $this->tab = 'overview';
        $due = $this->admission->depositRefundDue();
        $this->toast("Discharged. Final bill {$invoice->invoice_no}: ".money($invoice->total).($due ? '. Refund due to the patient: '.money($due).'.' : ''));
    }

    public function refundDeposit(IpdService $ipd): void
    {
        $this->authorize('billing.cancel');
        $this->validate(['refundAccount' => ['required', bank_account_exists()]], [], ['refundAccount' => 'account']);
        $line = $ipd->refundDeposit($this->admission, $this->refundAccount);
        $this->admission->refresh();
        $this->toast('Refunded '.money($line->amount).' of the deposit to '.$this->admission->patient->full_name.'.');
    }

    public function with(): array
    {
        $a = $this->admission->load(['patient.allergies', 'doctor', 'bed.ward', 'tpa', 'allocations.bed.ward', 'charges.doctor', 'invoice']);

        return [
            'a' => $a,
            'bedTotal' => $a->accruedBedCharges(),
            'chargeTotal' => (float) $a->charges->sum('amount'),
            'services' => ServiceCharge::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.money($s->price)])->all(),
            'doctors' => Staff::doctors()->active()->get()->mapWithKeys(fn ($d) => [$d->id => $d->display_name])->all(),
            'freeBeds' => $this->showTransfer ? Bed::with('ward')->where('status', 'available')->get()->mapWithKeys(fn ($b) => [$b->id => $b->label.' · '.money($b->dailyCharge()).'/day'])->all() : [],
            'labCatalog' => hospital()->hasModule('laboratory') ? LabTest::where('is_active', true)->orderBy('name')->get() : collect(),
            'imagingCatalog' => hospital()->hasModule('radiology') ? RadiologyTest::where('is_active', true)->orderBy('name')->get() : collect(),
            'labOrders' => $a->labOrders()->with('items.test')->latest()->get(),
            'radiologyOrders' => $a->radiologyOrders()->with('test')->latest()->get(),
            'open' => $a->status === 'admitted',
        ];
    }
}; ?>

<div>
    <x-page-header :title="$a->patient->full_name" :subtitle="$a->admission_no" :breadcrumbs="['IPD' => route('tenant.ipd.index')]">
        <x-status :value="$a->status" />
        @if ($open)
            @can('ipd.transfer')<button class="btn btn-light-info btn-sm" wire:click="$set('showTransfer', true)"><i class="ri-arrow-left-right-line me-1"></i>Transfer bed</button>@endcan
            @if (hospital()->hasModule('pharmacy'))
                @can('pharmacy.sell')<a href="{{ route('tenant.pharmacy.pos', ['admission' => $a->id]) }}" wire:navigate class="btn btn-light-primary btn-sm"><i class="ri-capsule-line me-1"></i>Issue medicines</a>@endcan
            @endif
            @can('ipd.discharge')<button class="btn btn-warning btn-sm" wire:click="$set('tab', 'discharge')"><i class="ri-logout-box-r-line me-1"></i>Discharge</button>@endcan
        @else
            <a href="{{ route('tenant.ipd.discharge-summary', $a->id) }}" target="_blank" class="btn btn-light btn-sm"><i class="ri-file-pdf-2-line me-1"></i>Discharge summary</a>
            @if ($a->invoice)<a href="{{ route('tenant.billing.show', $a->invoice) }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-bill-line me-1"></i>Final bill</a>@endif
        @endif
    </x-page-header>

    <div class="row g-4 mb-4">
        <div class="col-md-3"><x-stat-card title="Bed" :value="$a->bed?->label ?? '—'" icon="ri-hotel-bed-line" color="warning" :hint="$a->bed ? money($a->bed->dailyCharge()).' / day' : null" /></div>
        <div class="col-md-3"><x-stat-card title="Length of stay" :value="$a->lengthOfStay().' day(s)'" icon="ri-calendar-line" color="info" :hint="'Since '.fmt_datetime($a->admitted_at)" /></div>
        <div class="col-md-3">
            @if (! $open && $a->invoice)
                <x-stat-card title="Final bill" :value="money($a->invoice->total)" icon="ri-bill-line" color="primary" :hint="label($a->invoice->status).' · deposit '.money($a->deposit_amount)" />
            @else
                <x-stat-card title="Running bill" :value="money($bedTotal + $chargeTotal)" icon="ri-bill-line" color="primary" :hint="'Before tax · deposit '.money($a->deposit_amount)" />
            @endif
        </div>
        <div class="col-md-3"><x-stat-card title="Attending" :value="$a->doctor->display_name" icon="ri-stethoscope-line" color="success" :hint="$a->tpa?->name ?? 'Self-pay'" /></div>
    </div>

    @if ($a->patient->allergies->isNotEmpty())
        <div class="alert alert-danger py-2"><i class="ri-alarm-warning-line"></i> Allergies: {{ $a->patient->allergies->pluck('allergen')->join(', ') }}</div>
    @endif

    <div class="card mb-0">
        <div class="card-header pb-0 border-0">
            <ul class="nav nav-tabs card-header-tabs flex-nowrap overflow-auto">
                @foreach (['overview' => 'Overview', 'vitals' => 'Vitals', 'notes' => 'Progress notes', 'diagnoses' => 'Diagnoses', 'charges' => 'Charges', 'orders' => 'Orders', 'beds' => 'Bed history'] + ($open ? ['discharge' => 'Discharge'] : []) as $k => $l)
                    <li class="nav-item"><button class="nav-link text-nowrap {{ $tab === $k ? 'active' : '' }}" wire:click="$set('tab', '{{ $k }}')">{{ $l }}</button></li>
                @endforeach
            </ul>
        </div>
        <div class="card-body">
            @switch($tab)
                @case('vitals') <livewire:tenant.emr.vitals :patient="$a->patient" :visitable="$a" :key="'iv-'.$a->id" /> @break
                @case('notes') <livewire:tenant.emr.notes :patient="$a->patient" :visitable="$a" default-type="progress" :key="'in-'.$a->id" /> @break
                @case('diagnoses') <livewire:tenant.emr.diagnoses :patient="$a->patient" :visitable="$a" :key="'id-'.$a->id" /> @break
                @case('charges')
                    @if ($open)
                        @can('ipd.charges')
                            <div class="row g-2 align-items-end mb-3">
                                <x-form.select class="col-md-3 mb-0" label="Service" model="charge.service" :options="$services" placeholder="Custom charge" live />
                                <x-form.select class="col-md-2 mb-0" label="Category" model="charge.category" :options="['nursing' => 'Nursing', 'doctor_visit' => 'Doctor visit', 'procedure' => 'Procedure', 'consumable' => 'Consumable', 'medicine' => 'Medicine', 'other' => 'Other']" :placeholder="false" />
                                <x-form.input class="col-md-3 mb-0" label="Description" model="charge.description" />
                                <x-form.input class="col-md-1 mb-0" label="Qty" model="charge.quantity" type="number" step="0.5" />
                                <x-form.money class="col-md-1 mb-0" label="Price" model="charge.unit_price" />
                                <x-form.select class="col-md-1 mb-0" label="Doctor" model="charge.doctor_id" :options="$doctors" placeholder="—" />
                                <div class="col-md-1"><button title="Add" aria-label="Add" class="btn btn-primary w-100" wire:click="addCharge"><i class="ri-add-line"></i></button></div>
                            </div>
                        @endcan
                    @endif
                    <table class="table table-hms table-sm">
                        <thead><tr><th>Date</th><th>Category</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($a->allocations as $al)
                                @php $days = max(1, (int) ceil($al->from_at->diffInHours($al->to_at ?? now()) / 24)); @endphp
                                <tr class="table-light"><td>{{ fmt_date($al->from_at) }}</td><td>Bed</td><td>{{ $al->bed->label }} ({{ fmt_date($al->from_at) }} – {{ $al->to_at ? fmt_date($al->to_at) : 'now' }})</td><td class="text-end">{{ $days }}</td><td class="text-end">{{ money($al->charge_per_day) }}</td><td class="text-end">{{ money($days * $al->charge_per_day) }}</td><td></td></tr>
                            @endforeach
                            @forelse ($a->charges->sortByDesc('charged_at') as $c)
                                <tr><td>{{ fmt_datetime($c->charged_at) }}</td><td>{{ ['ot' => 'Surgery (OT)', 'bloodbank' => 'Blood bank', 'lab' => 'Lab', 'radiology' => 'Radiology'][$c->category] ?? label($c->category) }}</td><td>{{ $c->description }} @if ($c->doctor)<small class="text-muted">· {{ $c->doctor->display_name }}</small>@endif</td><td class="text-end">{{ (float) $c->quantity }}</td><td class="text-end">{{ money($c->unit_price) }}</td><td class="text-end">{{ money($c->amount) }}</td>
                                    <td class="text-end">@if (! $c->billed && $open)@can('ipd.charges')<button title="Remove" aria-label="Remove" class="btn btn-sm btn-link text-danger p-0" x-on:click="$confirm('Remove charge?', () => $wire.removeCharge({{ $c->id }}))"><i class="ri-close-line"></i></button>@endcan @endif</td></tr>
                            @empty
                                <tr><td colspan="7" class="text-muted text-center">No additional charges.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr><th colspan="5" class="text-end">Total</th><th class="text-end">{{ money($bedTotal + $chargeTotal) }}</th><th></th></tr></tfoot>
                    </table>
                    @break
                @case('orders')
                    @if ($open)
                        <div class="row g-3 mb-4">
                            @if ($labCatalog->isNotEmpty())
                                @can('lab.order')
                                    <div class="col-md-6">
                                        <label class="form-label">Order lab tests</label>
                                        <div class="border rounded p-2 mb-2" style="max-height: 180px; overflow-y: auto;">
                                            @foreach ($labCatalog as $t)<div class="form-check"><input class="form-check-input" type="checkbox" id="ilt{{ $t->id }}" value="{{ $t->id }}" wire:model="labTests"><label class="form-check-label fs-13" for="ilt{{ $t->id }}">{{ $t->name }}</label></div>@endforeach
                                        </div>
                                        @error('labTests')<div class="text-danger fs-12 mb-2">Tick at least one test.</div>@enderror
                                        <button class="btn btn-sm btn-info" wire:click="orderLab">Send to lab</button>
                                    </div>
                                @endcan
                            @endif
                            @if ($imagingCatalog->isNotEmpty())
                                @can('radiology.order')
                                    <div class="col-md-6">
                                        <label class="form-label">Order imaging</label>
                                        <div class="border rounded p-2 mb-2" style="max-height: 180px; overflow-y: auto;">
                                            @foreach ($imagingCatalog as $t)<div class="form-check"><input class="form-check-input" type="checkbox" id="irt{{ $t->id }}" value="{{ $t->id }}" wire:model="imaging"><label class="form-check-label fs-13" for="irt{{ $t->id }}">{{ $t->name }}</label></div>@endforeach
                                        </div>
                                        @error('imaging')<div class="text-danger fs-12 mb-2">Tick at least one study.</div>@enderror
                                        <button class="btn btn-sm btn-info" wire:click="orderImaging">Send to radiology</button>
                                    </div>
                                @endcan
                            @endif
                        </div>
                    @endif
                    @foreach ($labOrders as $o)<div class="fs-13 mb-1"><a href="{{ route('tenant.lab.order', $o) }}" wire:navigate>{{ $o->order_no }}</a> · {{ $o->items->pluck('test.name')->join(', ') }} <x-status :value="$o->status" /></div>@endforeach
                    @foreach ($radiologyOrders as $o)<div class="fs-13 mb-1"><a href="{{ route('tenant.radiology.order', $o) }}" wire:navigate>{{ $o->order_no }}</a> · {{ $o->test->name }} <x-status :value="$o->status" /></div>@endforeach
                    @if ($labOrders->isEmpty() && $radiologyOrders->isEmpty())<p class="text-muted">No investigations ordered.</p>@endif
                    @break
                @case('beds')
                    <ul class="list-group">
                        @foreach ($a->allocations->sortByDesc('from_at') as $al)
                            <li class="list-group-item d-flex justify-content-between"><span><strong>{{ $al->bed->label }}</strong> · {{ in_array($al->reason, ['admission', 'transfer'], true) ? label($al->reason) : $al->reason }}</span><span class="text-muted fs-13">{{ fmt_datetime($al->from_at) }} → {{ $al->to_at ? fmt_datetime($al->to_at) : 'current' }} · {{ money($al->charge_per_day) }}/day</span></li>
                        @endforeach
                    </ul>
                    @break
                @case('discharge')
                    @can('ipd.discharge')
                        <div class="alert alert-info py-2">Discharging closes the bed, adds bed-days &amp; all unbilled charges to a final invoice and adjusts the advance deposit ({{ money($a->deposit_amount) }}).</div>
                        <div class="row">
                            <x-form.select class="col-md-4" label="Discharge type" model="discharge.discharge_type" :options="['normal' => 'Normal / recovered', 'lama' => 'LAMA (against advice)', 'referred' => 'Referred', 'expired' => 'Expired', 'absconded' => 'Absconded']" :placeholder="false" />
                            <x-form.input class="col-md-4" label="Follow-up date" model="discharge.follow_up_date" type="date" />
                            <x-form.textarea class="col-12" label="Discharge summary (course in hospital, procedures, final diagnosis)" model="discharge.discharge_summary" rows="5" required />
                            <x-form.textarea class="col-md-6" label="Condition at discharge" model="discharge.discharge_condition" rows="3" />
                            <x-form.textarea class="col-md-6" label="Instructions / medications on discharge" model="discharge.discharge_instructions" rows="3" />
                        </div>
                        <button class="btn btn-warning" x-on:click="$confirm('Discharge {{ addslashes($a->patient->full_name) }} and generate the final bill?', () => $wire.dischargePatient(), { color: 'warning', confirmText: 'Discharge' })"><i class="ri-logout-box-r-line me-1"></i>Discharge &amp; generate final bill</button>
                    @endcan
                    @break
                @default
                    <div class="row g-4">
                        <div class="col-md-6">
                            <dl class="row fs-13 mb-0">
                                <dt class="col-5">Patient</dt><dd class="col-7"><a href="{{ route('tenant.patients.show', $a->patient) }}" wire:navigate>{{ $a->patient->full_name }}</a> ({{ $a->patient->uhid }})</dd>
                                <dt class="col-5">Admission type</dt><dd class="col-7">{{ label($a->admission_type) }}</dd>
                                <dt class="col-5">Reason</dt><dd class="col-7">{{ $a->reason ?: '—' }}</dd>
                                <dt class="col-5">Provisional Dx</dt><dd class="col-7">{{ $a->provisional_diagnosis ?: '—' }}</dd>
                                <dt class="col-5">Guardian</dt><dd class="col-7">{{ $a->guardian_name ?: '—' }} {{ $a->guardian_phone }}</dd>
                                <dt class="col-5">Insurance</dt><dd class="col-7">{{ $a->tpa?->name ?? 'Self-pay' }} {{ $a->insurance_policy_no }}</dd>
                                <dt class="col-5">Expected discharge</dt><dd class="col-7">{{ fmt_date($a->expected_discharge_date) }}</dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            @if (! $open)
                                <div class="border rounded p-3">
                                    <h6>Discharged {{ fmt_datetime($a->discharged_at) }} · {{ label($a->discharge_type) }}</h6>
                                    <p class="fs-13 mb-2">{!! nl2br(e($a->discharge_summary)) !!}</p>
                                    @if ($a->invoice)<p class="mb-0">Final bill <a href="{{ route('tenant.billing.show', $a->invoice) }}" wire:navigate>{{ $a->invoice->invoice_no }}</a> · {{ money($a->invoice->total) }} <x-status :value="$a->invoice->status" /></p>@endif
                                    @if ($a->deposit_amount > 0)
                                        <p class="fs-13 text-muted mb-0 mt-1">Deposit {{ money($a->deposit_amount) }} · used for the bill {{ money($a->depositUsed()) }}@if ($a->deposit_refunded) · refunded {{ money($a->deposit_refunded) }}@endif</p>
                                    @endif
                                    @if ($refundDue = $a->depositRefundDue())
                                        <div class="alert alert-warning py-2 mt-2 mb-0">
                                            <div class="mb-2"><i class="ri-refund-2-line me-1"></i>Refund due to the patient: <strong>{{ money($refundDue) }}</strong></div>
                                            @can('billing.cancel')
                                                <div class="d-flex flex-wrap align-items-end gap-2">
                                                    <x-form.account class="mb-0" label="Pay from" model="refundAccount" />
                                                    <button class="btn btn-sm btn-warning" x-on:click="$confirm('Pay {{ money($refundDue) }} back to {{ addslashes($a->patient->full_name) }}?', () => $wire.refundDeposit(), { color: 'warning', confirmText: 'Refund' })"><i class="ri-refund-2-line me-1"></i>Refund {{ money($refundDue) }}</button>
                                                </div>
                                            @endcan
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="border rounded p-3">
                                    <h6 class="mb-2">Running bill</h6>
                                    <div class="d-flex justify-content-between"><span>Bed charges</span><span>{{ money($bedTotal) }}</span></div>
                                    <div class="d-flex justify-content-between"><span>Services, medicines &amp; investigations</span><span>{{ money($chargeTotal) }}</span></div>
                                    <div class="d-flex justify-content-between"><span>Advance deposit</span><span>- {{ money($a->deposit_amount) }}</span></div>
                                    <hr class="my-2"><div class="d-flex justify-content-between fw-semibold"><span>Estimated due</span><span>{{ money(max(0, $bedTotal + $chargeTotal - $a->deposit_amount)) }}</span></div>
                                    <div class="fs-12 text-muted mt-1">Before tax; tax is added on the final bill.</div>
                                </div>
                            @endif
                        </div>
                    </div>
            @endswitch
        </div>
    </div>

    <x-modal wire:model="showTransfer" title="Transfer bed">
        <x-form.search-select label="New bed" model="newBed" :options="$freeBeds" required />
        <x-form.input label="Reason" model="transferReason" placeholder="e.g. Step-down from ICU" />
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-primary" wire:click="transfer">Transfer</button>
        </x-slot:footer>
    </x-modal>
</div>
