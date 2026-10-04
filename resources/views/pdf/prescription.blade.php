@extends('pdf.layout')
@section('title', 'Prescription '.$rx->prescription_no)
@section('footer', $hospital->setting('prescription_header') ?: 'This is a computer-generated e-prescription.')
@section('content')
    <table>
        <tr>
            <td style="width: 60%;">
                <h2>{{ $rx->doctor->display_name }}</h2>
                <div class="muted">{{ $rx->doctor->qualification }} · {{ $rx->doctor->specialization }}</div>
                @if ($rx->doctor->license_no)<div class="muted small">Reg. No: {{ $rx->doctor->license_no }}</div>@endif
            </td>
            <td class="right"><img src="{{ $qr }}" style="width: 64px;"><div class="small">{{ $rx->prescription_no }}</div></td>
        </tr>
    </table>

    <div class="box" style="margin-top: 8px;">
        <table>
            <tr>
                <td><span class="muted">Patient:</span> <b>{{ $rx->patient->full_name }}</b> ({{ $rx->patient->age_gender }})</td>
                <td><span class="muted">UHID:</span> {{ $rx->patient->uhid }}</td>
                <td class="right"><span class="muted">Date:</span> {{ $rx->created_at->format('d M Y') }}</td>
            </tr>
            <tr>
                <td colspan="2"><span class="muted">Allergies:</span> <span class="{{ $rx->patient->allergies->isNotEmpty() ? 'text-danger' : '' }}">{{ $rx->patient->allergies->pluck('allergen')->join(', ') ?: 'NKA' }}</span></td>
                <td class="right">@if ($v = $rx->patient->latestVital)<span class="muted">BP</span> {{ $v->bp ?? '—' }} · <span class="muted">Wt</span> {{ $v->weight ?? '—' }}kg @endif</td>
            </tr>
        </table>
    </div>

    @if ($diagnoses->isNotEmpty())
        <p><span class="bold">Diagnosis:</span> {{ $diagnoses->map(fn ($d) => trim(($d->icd_code ? $d->icd_code.' ' : '').$d->description))->join('; ') }}</p>
    @endif

    <h1 style="font-size: 22px; margin: 10px 0 4px;">℞</h1>
    <table class="grid">
        <thead><tr><th style="width: 4%;">#</th><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Route</th><th class="right">Qty</th></tr></thead>
        <tbody>
            @foreach ($rx->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><b>{{ $item->medicine_name }}</b>@if ($item->instructions)<div class="muted small">{{ $item->instructions }}</div>@endif</td>
                    <td>{{ $item->dosage }}</td><td>{{ $item->frequency }}</td><td>{{ $item->duration }}</td><td>{{ $item->route }}</td><td class="right">{{ $item->quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($rx->advice)
        <div class="title-bar">Advice</div>
        <p>{!! nl2br(e($rx->advice)) !!}</p>
    @endif
    @if ($rx->follow_up_date)<p><b>Follow-up:</b> {{ $rx->follow_up_date->format('l, d M Y') }}</p>@endif

    <table style="margin-top: 30px;">
        <tr><td></td><td class="right" style="width: 40%;">
            @if ($signature)<img src="{{ $signature }}" style="max-height: 40px;"><br>@endif
            <b>{{ $rx->doctor->display_name }}</b><div class="muted small">Digitally issued · {{ $rx->created_at->format('d M Y H:i') }}</div>
        </td></tr>
    </table>
@endsection
