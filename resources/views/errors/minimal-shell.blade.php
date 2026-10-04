<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Error' }} | {{ config('app.name') }}</title>
    <link rel="shortcut icon" href="{{ asset('assets/images/Favicon.png') }}">
    <script src="{{ asset_v('assets/js/layout/layout-auth.js') }}"></script>
    <link rel="stylesheet" href="{{ asset_v('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/app.min.css') }}">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-body-tertiary">
    <div class="card shadow-sm" style="max-width: 560px; width: 100%;">
        <div class="card-body p-5 text-center">
            <div class="avatar avatar-xl avatar-title bg-{{ $color ?? 'danger' }}-subtle text-{{ $color ?? 'danger' }} rounded-circle mx-auto mb-4" style="width:72px;height:72px;">
                <i class="{{ $icon ?? 'ri-error-warning-line' }} fs-1"></i>
            </div>
            {{ $slot ?? '' }}
            @yield('body')
        </div>
    </div>
</body>
</html>
