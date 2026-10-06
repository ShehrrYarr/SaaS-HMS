@extends('pdf.layout')

@section('title', 'Patient visit register')
@section('footer', $hospital->name.' · Patient visit register for FBR · '.fmt_date($from).' – '.fmt_date($to))

@section('styles')
    .grid td, .grid th { font-size: 8px; padding: 4px 5px; }
    .grid tr { page-break-inside: avoid; }
@endsection

@section('content')
    <table style="margin-bottom: 8px;">
        <tr>
            <td><h2>Patient visit register</h2><div class="muted">Prepared for the Federal Board of Revenue (FBR)</div></td>
            <td class="right">
                <div><span class="muted">Period:</span> <span class="bold">{{ fmt_date($from) }} – {{ fmt_date($to) }}</span></div>
                <div><span class="muted">Visits:</span> <span class="bold">{{ number_format($rows->count()) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr><th>#</th><th>Date</th><th>Visit</th><th>Patient</th><th>CNIC</th><th>Contact</th><th>Department / Doctor</th><th>Diagnosis</th><th>Services</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td style="white-space: nowrap;">{{ fmt_date($r['at']) }}<br><span class="muted">{{ fmt_time($r['at']) }}</span></td>
                    <td>{{ $r['type_label'] }}<br><span class="muted">{{ $r['ref'] }}</span></td>
                    <td><span class="bold">{{ $r['patient']['name'] }}</span><br><span class="muted">{{ collect([$r['patient']['uhid'], $r['patient']['age_gender']])->filter()->join(' · ') }}</span></td>
                    <td style="white-space: nowrap;">{{ $r['patient']['cnic'] ?? '—' }}</td>
                    <td>{{ $r['patient']['phone'] ?? '—' }}<br><span class="muted">{{ $r['patient']['address'] }}</span></td>
                    <td>{{ $r['department'] ?? '—' }}<br><span class="muted">{{ $r['doctor'] }}</span></td>
                    <td>{{ $r['diagnosis'] ?? '—' }}</td>
                    <td>
                        @forelse ($r['services'] as $group => $items)
                            <div><span class="muted">{{ $group }}:</span> {{ implode(', ', $items) }}</div>
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="center muted" style="padding: 20px;">No patient visits in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
