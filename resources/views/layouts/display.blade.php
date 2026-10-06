<!DOCTYPE html>
<html lang="en" class="h-100" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Queue' }} | {{ hospital()?->name }}</title>
    <link rel="shortcut icon" href="{{ asset('assets/images/hms-mark.png') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/app.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/hms.css') }}">
</head>

<body class="queue-board">
    {{ $slot }}
</body>

</html>
