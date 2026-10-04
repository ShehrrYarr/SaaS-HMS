<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Subscription')] class extends Component
{
    use Toasts, WithFileUploads;

    public ?int $payingId = null;

    public $proof;

    public string $reference = '';

    public string $method = 'bank_transfer';

    public ?int $planId = null;

    public string $cycle = 'monthly';

    public function mount(): void
    {
        $this->planId = hospital()->plan_id;
        $this->cycle = hospital()->billing_cycle;
    }

    public function submitProof(): void
    {
        $this->authorize('subscription.manage');
        $this->validate([
            'payingId' => 'required',
            'proof' => 'required|file|max:5120|mimes:pdf,jpg,jpeg,png',
            'reference' => 'required|string|max:100',
            'method' => 'required|in:bank_transfer,cheque,online,cash',
        ]);
        $invoice = SubscriptionInvoice::whereIn('status', ['unpaid', 'pending_verification'])->findOrFail($this->payingId);
        $invoice->update([
            'proof_path' => $this->proof->store(hospital()->storagePath('billing'), 'local'),
            'payment_reference' => $this->reference,
            'payment_method' => $this->method,
            'status' => 'pending_verification',
        ]);
        $this->reset('payingId', 'proof', 'reference');
        $this->toast('Payment proof submitted. The platform team will verify it shortly.');
    }

    public function requestPlan(SubscriptionService $billing): void
    {
        $this->authorize('subscription.manage');
        $this->validate(['planId' => 'required|exists:plans,id', 'cycle' => 'required|in:monthly,yearly']);
        $open = SubscriptionInvoice::whereIn('status', ['unpaid', 'pending_verification'])->first();
        if ($open) {
            $this->toast('Settle invoice '.$open->number.' first.', 'error');

            return;
        }
        $plan = Plan::where('is_active', true)->findOrFail($this->planId);
        $invoice = $billing->generateInvoice(hospital(), $plan, $this->cycle);
        $this->toast("Invoice {$invoice->number} created for the {$plan->name} plan. The plan activates once payment is verified.");
    }

    public function with(): array
    {
        $h = hospital()->load('plan');

        return [
            'h' => $h,
            'invoices' => SubscriptionInvoice::with('plan')->latest()->get(),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'bank' => platform_setting('bank_details'),
            'daysLeft' => $h->subscription_ends_at ? (int) today()->diffInDays($h->subscription_ends_at, false) : null,
        ];
    }
}; ?>

<div>
    <x-page-header title="Subscription" subtitle="Plan & billing" />

    @if ($h->isSuspended())
        <div class="alert alert-danger"><i class="ri-error-warning-line me-1"></i>Your hospital is suspended: {{ $h->suspended_reason }} Pay the outstanding invoice to restore access.</div>
    @elseif ($daysLeft !== null && $daysLeft <= 7)
        <div class="alert alert-warning">Your {{ $h->status === 'trial' ? 'trial' : 'subscription' }} {{ $daysLeft < 0 ? 'expired '.abs($daysLeft).' days ago' : 'ends in '.$daysLeft.' day(s)' }} ({{ fmt_date($h->subscription_ends_at) }}).</div>
    @endif

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <span class="badge bg-primary-subtle text-primary mb-2">Current plan</span>
                    <h3 class="mb-0">{{ $h->plan?->name ?? '—' }}</h3>
                    <p class="text-muted">{{ label($h->billing_cycle) }} · <x-status :value="$h->status" /></p>
                    <p class="mb-1">Paid until <strong>{{ fmt_date($h->subscription_ends_at) }}</strong></p>
                    <div class="d-flex flex-wrap gap-1 mt-3">
                        @foreach (config('hms.modules') as $key => $m)
                            <span class="badge {{ $h->hasModule($key) ? 'bg-success-subtle text-success' : 'bg-light text-muted' }}">{{ $m['label'] }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Change plan / renew</h6></div>
                <div class="card-body">
                    @foreach ($plans as $p)
                        <label class="d-flex align-items-start gap-2 border rounded p-2 mb-2 {{ $planId == $p->id ? 'border-primary' : '' }}" role="button">
                            <input type="radio" class="form-check-input mt-1" value="{{ $p->id }}" wire:model.live="planId">
                            <span class="flex-grow-1"><strong>{{ $p->name }}</strong> <span class="float-end">{{ money($cycle === 'yearly' ? $p->price_yearly : $p->price_monthly, $p->currency) }}</span><small class="d-block text-muted">{{ $p->description }}</small></span>
                        </label>
                    @endforeach
                    <select class="form-select mb-2" wire:model.live="cycle"><option value="monthly">Monthly</option><option value="yearly">Yearly (save ~2 months)</option></select>
                    <button class="btn btn-primary w-100" wire:click="requestPlan">Generate invoice</button>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Invoices</h6></div>
                <div class="table-responsive">
                    <table class="table table-hms align-middle mb-0">
                        <thead class="table-light"><tr><th>Invoice</th><th>Plan</th><th>Period</th><th>Due</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($invoices as $inv)
                                <tr>
                                    <td class="fw-semibold">{{ $inv->number }}</td><td>{{ $inv->plan?->name }} ({{ $inv->billing_cycle }})</td><td class="fs-12">{{ fmt_date($inv->period_start) }} – {{ fmt_date($inv->period_end) }}</td>
                                    <td>{{ fmt_date($inv->due_date) }}</td><td class="text-end">{{ money($inv->total, $inv->currency) }}</td><td><x-status :value="$inv->status" /></td>
                                    <td class="text-end">@if (in_array($inv->status, ['unpaid', 'pending_verification']))<button class="btn btn-sm btn-success" wire:click="$set('payingId', {{ $inv->id }})">{{ $inv->status === 'unpaid' ? 'Pay' : 'Update proof' }}</button>@endif</td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="7" message="No invoices yet." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($payingId)
                <div class="card mb-0 border-success">
                    <div class="card-header"><h6 class="card-title mb-0">Pay by bank transfer</h6></div>
                    <div class="card-body">
                        @if ($bank)<pre class="bg-body-tertiary p-3 rounded fs-13">{{ $bank }}</pre>@endif
                        <p class="fs-13 text-muted">Transfer the invoice total, then upload the receipt / screenshot. Your subscription is extended as soon as the payment is verified.</p>
                        <div class="row">
                            <x-form.select class="col-md-4" label="Method" model="method" :options="['bank_transfer' => 'Bank transfer', 'online' => 'Online / wallet', 'cheque' => 'Cheque', 'cash' => 'Cash deposit']" :placeholder="false" />
                            <x-form.input class="col-md-8" label="Transaction reference" model="reference" required />
                            <div class="col-12 mb-3"><label class="form-label">Receipt (PDF/image)</label><input type="file" class="form-control @error('proof') is-invalid @enderror" wire:model="proof">@error('proof')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        </div>
                        <button class="btn btn-success" wire:click="submitProof" wire:loading.attr="disabled">Submit for verification</button>
                        <button class="btn btn-light" wire:click="$set('payingId', null)">Cancel</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
