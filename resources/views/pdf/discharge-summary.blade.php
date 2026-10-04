@extends('pdf.layout')
@section('title', 'Discharge summary '.$a->admission_no)
@section('content')
    <h2 class="center" style="margin-bottom: 8px;">DISCHARGE SUMMARY</h2>
    <table class="box">
        <tr><td><span class="muted">Patient:</span> <b>{{ $a->patient->full_name }}</b> ({{ $a->patient->age_gender }})</td><td><span class="muted">UHID:</span> {{ $a->patient->uhid }}</td><td><span class="muted">Admission:</span> {{ $a->admission_no }}</td></tr>
        <tr><td><span class="muted">Admitted:</span> {{ $a->admitted_at->format('d M Y H:i') }}</td><td><span class="muted">Discharged:</span> {{ optional($a->discharged_at)->format('d M Y H:i') }}</td><td><span class="muted">Stay:</span> {{ $a->lengthOfStay() }} day(s)</td></tr>
        <tr><td><span class="muted">Consultant:</span> {{ $a->doctor->display_name }}</td><td><span class="muted">Department:</span> {{ $a->department?->name }}</td><td><span class="muted">Discharge type:</span> {{ strtoupper($a->discharge_type ?? '') }}</td></tr>
        <tr><td colspan="3"><span class="muted">Allergies:</span> {{ $a->patient->allergies->pluck('allergen')->join(', ') ?: 'NKA' }}</td></tr>
    </table>

    <div class="title-bar">Reason for admission</div>
    <p>{{ $a->reason ?: '—' }} @if ($a->provisional_diagnosis)<br><span class="muted">Provisional diagnosis:</span> {{ $a->provisional_diagnosis }}@endif</p>

    @if ($a->diagnoses->isNotEmpty())
        <div class="title-bar">Final diagnosis</div>
        <ul>@foreach ($a->diagnoses as $d)<li>{{ $d->icd_code ? $d->icd_code.' – ' : '' }}{{ $d->description }} <span class="muted">({{ $d->type }})</span></li>@endforeach</ul>
    @endif

    <div class="title-bar">Course in hospital</div>
    <p>{!! nl2br(e($a->discharge_summary)) !!}</p>

    @if ($a->surgeries->isNotEmpty())
        <div class="title-bar">Procedures</div>
        <ul>@foreach ($a->surgeries as $s)<li>{{ $s->procedure_name }} — {{ $s->scheduled_start->format('d M Y') }} ({{ $s->status }})</li>@endforeach</ul>
    @endif

    @if ($a->labOrders->isNotEmpty())
        <div class="title-bar">Investigations</div>
        <p>{{ $a->labOrders->flatMap->items->pluck('test.name')->unique()->join(', ') }}</p>
    @endif

    @if ($a->discharge_condition)
        <div class="title-bar">Condition at discharge</div>
        <p>{!! nl2br(e($a->discharge_condition)) !!}</p>
    @endif

    <div class="title-bar">Advice &amp; medications on discharge</div>
    <p>{!! nl2br(e($a->discharge_instructions ?: '—')) !!}</p>
    @php $rx = $a->prescriptions->sortByDesc('created_at')->first(); @endphp
    @if ($rx)
        <table class="grid"><thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Duration</th></tr></thead><tbody>
            @foreach ($rx->items as $i)<tr><td>{{ $i->medicine_name }}</td><td>{{ $i->dosage }}</td><td>{{ $i->frequency }}</td><td>{{ $i->duration }}</td></tr>@endforeach
        </tbody></table>
    @endif
    @if ($a->follow_up_date)<p><b>Follow-up:</b> {{ $a->follow_up_date->format('l, d M Y') }}</p>@endif

    <table style="margin-top: 30px;"><tr><td></td><td class="right" style="width: 40%;">
        @if ($signature)<img src="{{ $signature }}" style="max-height: 40px;"><br>@endif
        <b>{{ $a->doctor->display_name }}</b><div class="muted small">{{ $a->doctor->qualification }}</div>
    </td></tr></table>
@endsection
