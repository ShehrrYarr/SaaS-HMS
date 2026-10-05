@extends('pdf.layout', ['noHeader' => true])
@section('title', 'Patient card '.$patient->uhid)
@section('styles') @page { margin: 8px; } body { font-size: 8px; } .footer { display: none; } @endsection
@section('content')
    <table style="border: 1px solid #0d6efd; border-radius: 6px;">
        <tr><td colspan="2" style="background: #0d6efd; color: #fff; padding: 3px 6px;"><b>{{ $hospital->name }}</b> <span style="float: right;">PATIENT CARD</span></td></tr>
        <tr>
            <td style="width: 32%; padding: 4px; text-align: center;">
                @if ($photo)<img src="{{ $photo }}" style="width: 52px; height: 60px;">@else<table style="width: 52px; height: 52px; margin: auto; background: #e5e7eb;"><tr><td style="height: 52px; padding: 0; text-align: center; vertical-align: middle; font-size: 18px;">{{ $patient->initials() }}</td></tr></table>@endif
            </td>
            <td style="padding: 4px;">
                <b style="font-size: 10px;">{{ $patient->full_name }}</b><br>
                UHID: <b>{{ $patient->uhid }}</b><br>
                {{ $patient->age_gender }} · Blood: {{ $patient->blood_group ?: '—' }}<br>
                {{ $patient->phone }}<br>
                <span class="muted">Emergency: {{ $patient->emergency_contact_phone ?: '—' }}</span>
            </td>
        </tr>
        <tr><td colspan="2" style="text-align: center; padding: 2px;"><img src="{{ $barcode }}" style="height: 24px; width: 180px;"></td></tr>
    </table>
@endsection
