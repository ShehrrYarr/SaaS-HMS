@extends('pdf.layout', ['noHeader' => true])
@section('title', 'Receipt '.$sale->sale_no)
@section('styles') @page { margin: 10px 10px 30px; } body { font-size: 8.5px; } .footer { bottom: -20px; } @endsection
@section('content')
    <div class="center">
        <img src="{{ $logo }}" style="max-height: 30px; max-width: 140px;"><br>
        <b>{{ $hospital->name }}</b><br>
        <span class="small">{{ $hospital->phone }}</span><br>
        <b>PHARMACY RECEIPT</b>
    </div>
    <hr>
    <table class="small">
        <tr><td>{{ $sale->sale_no }}</td><td class="right">{{ $sale->created_at->format('d/m/Y H:i') }}</td></tr>
        <tr><td colspan="2">{{ $sale->patient ? $sale->patient->full_name.' ('.$sale->patient->uhid.')' : ($sale->customer_name ?: 'Walk-in customer') }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($sale->items as $i)
            <tr><td colspan="3"><b>{{ $i->medicine->label }}</b> <span class="small">B:{{ $i->batch->batch_no }} E:{{ $i->batch->expiry_date->format('m/y') }}</span></td></tr>
            <tr><td class="small">{{ $i->quantity }} × {{ number_format($i->unit_price, 2) }}</td><td></td><td class="right">{{ number_format($i->total, 2) }}</td></tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="right">{{ number_format($sale->subtotal, 2) }}</td></tr>
        @if ($sale->discount > 0)<tr><td>Discount</td><td class="right">-{{ number_format($sale->discount, 2) }}</td></tr>@endif
        @if ($sale->tax > 0)<tr><td>{{ $hospital->tax_label }}</td><td class="right">{{ number_format($sale->tax, 2) }}</td></tr>@endif
        <tr><td class="bold">TOTAL ({{ $hospital->currency }})</td><td class="right bold">{{ number_format($sale->total, 2) }}</td></tr>
        <tr><td>Paid ({{ ucwords(str_replace('_', ' ', $sale->payment_method)) }})</td><td class="right">{{ number_format($sale->payment_method === 'ipd_credit' ? 0 : $sale->paid_amount, 2) }}</td></tr>
    </table>
    @if ($sale->status !== 'completed')<p class="center text-danger">{{ strtoupper($sale->status) }}</p>@endif
    <hr>
    <p class="center small">Served by {{ $sale->seller?->name }}<br>Get well soon!</p>
@endsection
