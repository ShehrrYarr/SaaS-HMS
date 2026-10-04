@extends('pdf.layout', ['noHeader' => true])
@section('title', 'Sample labels '.$order->order_no)
@section('styles') @page { margin: 4px; } body { font-size: 7px; } .footer { display: none; } .label { page-break-after: always; } .label:last-child { page-break-after: auto; } @endsection
@section('content')
    @foreach ($labels as $l)
        <div class="label">
            <b>{{ \Illuminate\Support\Str::limit($order->patient->full_name, 24) }}</b> · {{ $order->patient->age_gender }}<br>
            {{ $order->patient->uhid }} · {{ $l['item']->test->code }} · {{ $l['item']->test->container }}<br>
            <img src="{{ $l['barcode'] }}" style="height: 26px; width: 130px;"><br>
            <span style="letter-spacing: 1px;">{{ $l['item']->sample_barcode }}</span> · {{ optional($l['item']->sample_collected_at)->format('d/m H:i') }}
        </div>
    @endforeach
@endsection
