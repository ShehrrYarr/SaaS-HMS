@extends('pdf.layout')
@section('title', 'Invoice '.$invoice->invoice_no)
@section('footer', $hospital->setting('invoice_footer') ?: 'Thank you.')
@section('content')
    <table>
        <tr>
            <td><h2>INVOICE</h2><div class="muted">{{ $invoice->invoice_no }}</div></td>
            <td class="right"><img src="{{ $qr }}" style="width: 60px;"></td>
        </tr>
    </table>
    <table style="margin: 8px 0;">
        <tr>
            <td class="box" style="width: 55%;">
                <b>Bill to</b><br>{{ $invoice->patient->full_name }} ({{ $invoice->patient->uhid }})<br>
                <span class="muted">{{ $invoice->patient->phone }}</span><br>
                <span class="muted">{{ collect([$invoice->patient->address, $invoice->patient->city])->filter()->join(', ') }}</span>
                @if ($invoice->tpa)<br><span class="muted">Payer:</span> {{ $invoice->tpa->name }} · Policy {{ $invoice->patient->insurance_policy_no }}@endif
            </td>
            <td style="width: 45%; padding-left: 10px;">
                <table class="totals">
                    <tr><td class="muted">Invoice date</td><td class="right">{{ $invoice->invoice_date->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Due date</td><td class="right">{{ optional($invoice->due_date)->format('d M Y') }}</td></tr>
                    @if ($invoice->admission)<tr><td class="muted">Admission</td><td class="right">{{ $invoice->admission->admission_no }}</td></tr>@endif
                    <tr><td class="muted">Status</td><td class="right bold">{{ strtoupper($invoice->status) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead><tr><th>#</th><th>Service</th><th>Description</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Disc.</th><th class="right">{{ $hospital->tax_label }}</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $i => $item)
                <tr><td>{{ $i + 1 }}</td><td>{{ strtoupper($item->service_type) }}</td><td>{{ $item->description }}@if ($item->doctor)<div class="muted small">{{ $item->doctor->display_name }}</div>@endif</td>
                    <td class="right">{{ (float) $item->quantity }}</td><td class="right">{{ money($item->unit_price) }}</td><td class="right">{{ money($item->discount) }}</td><td class="right">{{ money($item->tax_amount) }}</td><td class="right">{{ money($item->total) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 8px;">
        <tr>
            <td style="width: 55%; vertical-align: top;">
                @if ($invoice->payments->isNotEmpty())
                    <b>Payments</b>
                    <table class="grid small">
                        @foreach ($invoice->payments as $p)<tr><td>{{ $p->payment_no }}</td><td>{{ $p->paid_at->format('d M Y') }}</td><td>{{ $p->accountLabel() }}</td><td class="right">{{ $p->is_refund ? '-' : '' }}{{ money($p->amount) }}</td></tr>@endforeach
                    </table>
                @endif
                @if ($invoice->notes)<p class="muted small">{!! nl2br(e($invoice->notes)) !!}</p>@endif
                @if ($invoice->balance > 0 && ($banks = \App\Models\BankAccount::active()->where('type', 'bank')->where('show_to_patients', true)->orderBy('name')->get())->isNotEmpty())
                    <p class="small" style="margin-top: 6px;"><b>Pay by bank transfer</b> (quote {{ $invoice->invoice_no }}):<br>
                        @foreach ($banks as $b){{ $b->name }} · {{ $b->account_title }} · {{ $b->account_number }}@if ($b->iban) · IBAN {{ $b->iban }}@endif<br>@endforeach
                    </p>
                @endif
            </td>
            <td style="width: 45%; padding-left: 10px;">
                <table class="totals">
                    <tr><td>Subtotal</td><td class="right">{{ money($invoice->subtotal) }}</td></tr>
                    @if ($invoice->discount > 0)<tr><td>Discount</td><td class="right">- {{ money($invoice->discount) }}</td></tr>@endif
                    <tr><td>{{ $hospital->tax_label }}</td><td class="right">{{ money($invoice->tax) }}</td></tr>
                    <tr style="border-top: 1px solid #111;"><td class="bold">Total</td><td class="right bold">{{ money($invoice->total) }}</td></tr>
                    @if ($invoice->insurance_amount > 0)<tr><td>Insurance</td><td class="right">- {{ money($invoice->insurance_amount) }}</td></tr>@endif
                    <tr><td>Paid</td><td class="right">- {{ money($invoice->paid_amount) }}</td></tr>
                    <tr><td class="bold">Balance due</td><td class="right bold">{{ money(max(0, $invoice->balance)) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <p class="muted small" style="margin-top: 20px;">Prepared by {{ $invoice->creator?->name ?? 'system' }}. Computer-generated invoice.</p>
@endsection
