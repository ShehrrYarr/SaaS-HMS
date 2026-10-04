@extends('pdf.layout')
@section('title', 'Lab report '.$order->order_no)
@section('footer', ($hospital->setting('report_footer') ?: 'Results relate only to the samples tested.').' · Verify: '.$verifyUrl)
@section('content')
    @include('pdf.partials.lab-report-body', ['img' => fn ($src) => $src])
@endsection
