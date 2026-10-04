{{-- Shared by the dompdf report and the TCPDF digitally-signed report ($img formats image sources per engine). --}}
<div style="text-align:center; font-size: 14px; font-weight: bold; margin: 4px 0 8px;">LABORATORY REPORT{{ $order->status !== 'approved' ? ' (PARTIAL)' : '' }}</div>
<table cellpadding="3" style="border: 1px solid #e5e7eb; width: 100%;">
    <tr>
        <td width="38%"><span style="color:#6b7280;">Patient:</span> <b>{{ $order->patient->full_name }}</b></td>
        <td width="30%"><span style="color:#6b7280;">Age / Sex:</span> {{ $order->patient->age_gender }}</td>
        <td width="32%"><span style="color:#6b7280;">UHID:</span> {{ $order->patient->uhid }}</td>
    </tr>
    <tr>
        <td><span style="color:#6b7280;">Referred by:</span> {{ $order->doctor?->display_name ?? 'Self' }}</td>
        <td><span style="color:#6b7280;">Order:</span> {{ $order->order_no }}</td>
        <td><span style="color:#6b7280;">Collected:</span> {{ optional($order->items->first()?->sample_collected_at)->format('d M Y H:i') }}</td>
    </tr>
    <tr>
        <td colspan="2"><span style="color:#6b7280;">Reported:</span> {{ optional($order->approved_at)->format('d M Y H:i') }}</td>
        <td><span style="color:#6b7280;">Priority:</span> {{ strtoupper($order->priority) }}</td>
    </tr>
</table>

@foreach ($order->items as $item)
    <div style="background-color:#0d6efd; color:#ffffff; padding:4px 6px; font-weight:bold; margin-top:10px;">{{ $item->test->name }}
        <span style="font-weight:normal; font-size:8px;"> · Sample {{ $item->sample_barcode }} ({{ ucfirst($item->test->sample_type) }}){{ $item->test->method ? ' · Method: '.$item->test->method : '' }}</span></div>
    <table cellpadding="3" style="width: 100%; border-collapse: collapse;">
        <tr style="background-color:#f3f4f6;"><td width="40%"><b>Parameter</b></td><td width="20%"><b>Result</b></td><td width="12%"><b>Unit</b></td><td width="28%"><b>Reference range</b></td></tr>
        @foreach ($item->test->parameters->sortBy('sort_order') as $p)
            @php $r = $item->results->firstWhere('lab_test_parameter_id', $p->id); $flag = $r?->flag; @endphp
            @if ($r && $r->value !== null)
                <tr>
                    <td width="40%" style="border-bottom: 1px solid #e5e7eb;">{{ $p->name }}</td>
                    <td width="20%" style="border-bottom: 1px solid #e5e7eb; {{ in_array($flag, ['high', 'critical_high', 'critical_low', 'abnormal']) ? 'color:#dc2626; font-weight:bold;' : ($flag === 'low' ? 'color:#1d4ed8; font-weight:bold;' : '') }}">
                        {{ $r->value }} {{ match ($flag) { 'high' => 'H', 'low' => 'L', 'critical_high' => 'HH', 'critical_low' => 'LL', 'abnormal' => '*', default => '' } }}
                    </td>
                    <td width="12%" style="border-bottom: 1px solid #e5e7eb;">{{ $p->unit }}</td>
                    <td width="28%" style="border-bottom: 1px solid #e5e7eb;">{{ $p->rangeText($order->patient->gender) }}</td>
                </tr>
            @endif
        @endforeach
    </table>
    @if ($item->remarks)<p style="font-size:9px;"><b>Remarks:</b> {{ $item->remarks }}</p>@endif
@endforeach

<p style="font-size:8px; color:#6b7280; margin-top:8px;">H/L = above/below reference range · HH/LL = critical value · * = abnormal finding</p>

<table cellpadding="3" style="margin-top: 20px; width: 100%;">
    <tr>
        <td width="30%"><img src="{{ $img($qr) }}" width="70" height="70"><br><span style="font-size:7px; color:#6b7280;">Scan to verify authenticity</span></td>
        <td width="35%"></td>
        <td width="35%" align="right" style="text-align:right;">
            @if ($signature)<img src="{{ $img($signature) }}" height="36"><br>@endif
            <b>{{ $approver?->name ?? 'Pathologist' }}</b><br>
            <span style="font-size:8px; color:#6b7280;">Approved &amp; electronically signed<br>{{ optional($order->approved_at)->format('d M Y H:i') }}</span>
        </td>
    </tr>
</table>
<p style="text-align:center; font-size:8px; color:#6b7280;">— End of report —</p>
