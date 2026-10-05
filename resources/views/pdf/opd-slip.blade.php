@extends('pdf.layout', ['noHeader' => true])
@section('title', 'OPD slip '.$visit->visit_no)
@section('styles') @page { margin: 10px 10px 24px; } body { font-size: 9px; } .footer { bottom: -16px; } @endsection
@section('content')
    <div class="center">
        <img src="{{ $logo }}" style="max-height: 28px; max-width: 140px;"><br>
        <b>{{ $hospital->name }}</b><br><span class="small">OPD TOKEN</span>
        <div style="font-size: 40px; font-weight: bold; margin: 4px 0;">{{ $visit->token_no }}</div>
        <b>{{ $visit->doctor->display_name }}</b><br>
        <span class="small">{{ $visit->doctor->department?->name }} {{ $room ? '· Room '.$room : '' }}</span>
    </div>
    <hr>
    <table class="small">
        <tr><td>Patient</td><td class="right"><b>{{ $visit->patient->full_name }}</b></td></tr>
        <tr><td>UHID</td><td class="right">{{ $visit->patient->uhid }}</td></tr>
        <tr><td>Age/Sex</td><td class="right">{{ $visit->patient->age_gender }}</td></tr>
        <tr><td>Visit</td><td class="right">{{ $visit->visit_no }} · {{ ucwords(str_replace('_', ' ', $visit->visit_type)) }}</td></tr>
        <tr><td>Date</td><td class="right">{{ $visit->visit_date->format('d M Y h:i A') }}</td></tr>
        {{-- The amount to pay is the invoice total (fee + tax), not the bare doctor fee. --}}
        <tr><td>{{ $visit->invoice ? 'Amount' : 'Fee' }}</td><td class="right">{{ money($visit->invoice?->total ?? $visit->fee) }} @if ($visit->invoice)({{ strtoupper($visit->invoice->status) }})@endif</td></tr>
    </table>
    <div class="center" style="margin-top: 6px;"><img src="{{ $barcode }}" style="height: 22px; width: 150px;"></div>
    <p class="center small">Please wait for your token to be called.</p>
@endsection
