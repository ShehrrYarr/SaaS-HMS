<?php

use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\HmsNotification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.portal')] #[Title('Bills & Payments')] class extends Component
{
    use Toasts, WithFileUploads;

    public ?int $payingId = null;

    public string $reference = '';

    public string $amount = '';

    public $proof;

    public function mount(): void
    {
        abort_unless(hospital()->hasModule('billing'), 403);
    }

    public function report(): void
    {
        $this->validate(['payingId' => 'required', 'reference' => 'required|string|max:100', 'amount' => 'required|numeric|min:1', 'proof' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png']);
        $patient = auth()->user()->patient;
        $invoice = $patient->invoices()->whereIn('status', ['unpaid', 'partial'])->findOrFail($this->payingId);
        $path = $this->proof?->store(hospital()->storagePath("patients/{$patient->id}/payments"), 'local');

        $invoice->update(['notes' => trim(($invoice->notes ? $invoice->notes."\n" : '')."Patient reported transfer {$this->reference} of ".money($this->amount).' on '.now()->format('d M Y H:i'))]);
        AuditLog::record('payment_reported', $invoice, [], ['reference' => $this->reference, 'amount' => $this->amount, 'proof' => $path], 'Patient reported a bank transfer');

        User::where('hospital_id', hospital()->id)->permission('billing.collect')->get()->each(fn ($u) => $u->notify(new HmsNotification(
            'Patient payment to verify', "{$patient->full_name} reported ".money($this->amount)." for {$invoice->invoice_no} (ref {$this->reference})",
            route('tenant.billing.show', $invoice), 'ri-bank-line', 'warning'
        )));

        $this->reset('payingId', 'reference', 'amount', 'proof');
        $this->toast('Thank you! The billing desk will confirm your payment shortly.');
    }

    public function with(): array
    {
        return [
            'invoices' => auth()->user()->patient->invoices()->with('payments')->where('status', '!=', 'cancelled')->latest('invoice_date')->get(),
            'bank' => hospital()->setting('bank_details'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Bills & Payments" subtitle="My invoices" />
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hms align-middle mb-0">
                <thead class="table-light"><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Due</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($invoices as $inv)
                        <tr>
                            <td class="fw-semibold">{{ $inv->invoice_no }}</td><td>{{ fmt_date($inv->invoice_date) }}</td><td class="text-end">{{ money($inv->total) }}</td><td class="text-end">{{ money($inv->paid_amount) }}</td>
                            <td class="text-end {{ $inv->balance > 0 ? 'text-danger fw-semibold' : '' }}">{{ money(max(0, $inv->balance)) }}</td><td><x-status :value="$inv->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('portal.bill.pdf', $inv->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-download-2-line"></i></a>
                                @if (in_array($inv->status, ['unpaid', 'partial']))<button class="btn btn-sm btn-success" wire:click="$set('payingId', {{ $inv->id }})">Pay</button>@endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="7" message="No bills." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($payingId)
        <div class="card mb-0 border-success">
            <div class="card-header"><h6 class="card-title mb-0">Pay online / bank transfer</h6></div>
            <div class="card-body">
                <p class="fs-13">You can pay at the hospital billing counter (cash/card), or transfer to the hospital account and report it here.</p>
                @if ($bank)<pre class="bg-body-tertiary p-3 rounded fs-13">{{ $bank }}</pre>@endif
                <div class="row">
                    <x-form.input class="col-md-4" label="Amount transferred" model="amount" type="number" step="0.01" required />
                    <x-form.input class="col-md-8" label="Transaction reference" model="reference" required />
                    <div class="col-12 mb-3"><label class="form-label">Receipt (optional)</label><input type="file" class="form-control" wire:model="proof"></div>
                </div>
                <button class="btn btn-success" wire:click="report">Report payment</button>
                <button class="btn btn-light" wire:click="$set('payingId', null)">Cancel</button>
            </div>
        </div>
    @endif
</div>
