@extends('errors.minimal-shell', ['title' => 'Report verification', 'icon' => $order ? 'ri-shield-check-line' : 'ri-shield-cross-line', 'color' => $order ? 'success' : 'danger'])
@section('body')
    @if ($order)
        <h4 class="mb-1 text-success">Authentic laboratory report</h4>
        <p class="text-muted">This report was issued and approved by <strong>{{ $hospital->name }}</strong>.</p>
        <table class="table table-sm text-start mb-0">
            <tr><th>Report no.</th><td>{{ $order->order_no }}</td></tr>
            <tr><th>Patient</th><td>{{ $patientName }}</td></tr>
            <tr><th>Tests</th><td>{{ $order->items->where('status', 'approved')->pluck('test.name')->join(', ') }}</td></tr>
            <tr><th>Approved</th><td>{{ $order->approved_at?->format('d M Y H:i') }} by {{ $order->approver?->name }}</td></tr>
        </table>
        <p class="text-muted fs-12 mt-3 mb-0">Results are not shown here. Compare these details with the printed report.</p>
    @else
        <h4 class="mb-1 text-danger">Report not found</h4>
        <p class="text-muted mb-0">This verification code does not match any approved report. The document may be altered or invalid.</p>
    @endif
@endsection
