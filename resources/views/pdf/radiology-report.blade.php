@extends('pdf.layout')
@section('title', 'Imaging report '.$o->order_no)
@section('content')
    <h2 class="center" style="margin-bottom: 8px;">{{ strtoupper(\App\Models\RadiologyTest::MODALITIES[$o->test->modality] ?? $o->test->modality) }} REPORT</h2>
    <table class="box">
        <tr><td><span class="muted">Patient:</span> <b>{{ $o->patient->full_name }}</b> ({{ $o->patient->age_gender }})</td><td><span class="muted">UHID:</span> {{ $o->patient->uhid }}</td><td><span class="muted">Order:</span> {{ $o->order_no }}</td></tr>
        <tr><td><span class="muted">Study:</span> <b>{{ $o->test->name }}</b></td><td><span class="muted">Referred by:</span> {{ $o->doctor?->display_name ?? 'Self' }}</td><td><span class="muted">Performed:</span> {{ optional($o->performed_at)->format('d M Y H:i') }}</td></tr>
        @if ($o->study_instance_uid)<tr><td colspan="3" class="small muted">Study UID: {{ $o->study_instance_uid }}</td></tr>@endif
    </table>
    @if ($o->clinical_history)<p><span class="muted">Clinical history:</span> {{ $o->clinical_history }}</p>@endif
    <div class="title-bar">Findings</div>
    <p>{!! nl2br(e($o->findings)) !!}</p>
    <div class="title-bar">Impression</div>
    <p class="bold">{!! nl2br(e($o->impression)) !!}</p>
    @if ($o->status !== 'approved')<p class="text-danger center">PRELIMINARY — NOT YET FINALISED</p>@endif
    <table style="margin-top: 30px;"><tr><td></td><td class="right" style="width: 40%;">
        @if ($signature)<img src="{{ $signature }}" style="max-height: 40px;"><br>@endif
        <b>{{ $o->radiologist?->name ?? 'Radiologist' }}</b><div class="muted small">Reported {{ optional($o->reported_at)->format('d M Y H:i') }}</div>
    </td></tr></table>
@endsection
