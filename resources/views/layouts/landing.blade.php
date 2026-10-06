<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? platform_setting('platform_name', config('app.name')) }}</title>
    <meta name="description" content="Cloud hospital management system: patients & EMR, OPD, IPD, pharmacy POS, laboratory, radiology, billing & insurance, HR & payroll, OT, blood bank, telemedicine and a patient portal — multi-tenant SaaS.">
    <link rel="shortcut icon" href="{{ asset('assets/images/hms-mark.png') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/app.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/hms.css') }}">
</head>

<body class="lp">
    {{ $slot }}
    <script src="{{ asset('assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
</body>

</html>
