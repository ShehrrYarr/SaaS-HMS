<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 10px; color: #1f2937; }
        table { width: 100%; border-collapse: collapse; }
        .grid th { background: #f3f4f6; text-align: left; padding: 6px; border-bottom: 1px solid #d1d5db; }
        .grid td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .box { border: 1px solid #e5e7eb; padding: 8px; }
    </style>
</head>
<body>
    <table style="border-bottom: 2px solid #0d6efd; padding-bottom: 8px; margin-bottom: 14px;">
        <tr>
            <td><img src="{{ $logo }}" style="max-height: 40px;"><br><b>{{ platform_setting('platform_name', config('app.name')) }}</b><br><span class="muted">{{ platform_setting('support_email') }} {{ platform_setting('support_phone') }}</span></td>
            <td class="right"><h2 style="margin: 0;">INVOICE</h2>{{ $invoice->number }}<br><span class="muted">Status: {{ strtoupper(str_replace('_', ' ', $invoice->status)) }}</span></td>
        </tr>
    </table>
    <table>
        <tr>
            <td class="box" style="width: 55%;"><b>Billed to</b><br>{{ $customer->name }}<br><span class="muted">{{ $customer->fullAddress() }}</span><br><span class="muted">{{ $customer->email }}</span></td>
            <td style="padding-left: 12px;">
                <table>
                    <tr><td class="muted">Issued</td><td class="right">{{ $invoice->created_at->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Due</td><td class="right">{{ $invoice->due_date->format('d M Y') }}</td></tr>
                    @if ($invoice->paid_at)<tr><td class="muted">Paid</td><td class="right">{{ $invoice->paid_at->format('d M Y') }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>
    <table class="grid" style="margin-top: 14px;">
        <thead><tr><th>Description</th><th>Period</th><th class="right">Amount</th></tr></thead>
        <tbody><tr><td>{{ $invoice->plan->name }} plan — {{ ucfirst($invoice->billing_cycle) }} subscription</td><td>{{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</td><td class="right">{{ money($invoice->amount, $invoice->currency) }}</td></tr></tbody>
    </table>
    <table style="margin-top: 8px;">
        <tr><td style="width: 60%;"></td><td>
            <table>
                <tr><td>Subtotal</td><td class="right">{{ money($invoice->amount, $invoice->currency) }}</td></tr>
                <tr><td>Tax</td><td class="right">{{ money($invoice->tax, $invoice->currency) }}</td></tr>
                <tr><td><b>Total</b></td><td class="right"><b>{{ money($invoice->total, $invoice->currency) }}</b></td></tr>
            </table>
        </td></tr>
    </table>
    @if ($bank = platform_setting('bank_details'))
        <div class="box" style="margin-top: 16px;"><b>Payment instructions</b><br>{!! nl2br(e($bank)) !!}<br><span class="muted">Please quote invoice number {{ $invoice->number }} as the payment reference.</span></div>
    @endif
    <p class="muted" style="margin-top: 20px;">{{ platform_setting('invoice_footer') }}</p>
</body>
</html>
