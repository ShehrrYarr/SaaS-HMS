@props(['area' => 'tenant', 'title' => null])
@php
    $brand = $area === 'admin' ? config('app.name') : (hospital()?->name ?? config('app.name'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-100">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' | ' : '' }}{{ $brand }}</title>
    <link rel="shortcut icon" href="{{ asset('assets/images/Favicon.png') }}">

    {{-- Herozi layout engine (theme, sidebar mode, colours). Kept in <head> so wire:navigate never re-runs it. --}}
    <script src="{{ asset_v('assets/js/layout/layout-default.js') }}"></script>
    <script src="{{ asset_v('assets/js/layout/layout.js') }}"></script>

    <link rel="stylesheet" href="{{ asset('assets/libs/choices.js/public/assets/styles/choices.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/libs/simplebar/simplebar.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/bootstrap.min.css') }}" id="bootstrap-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/app.min.css') }}" id="app-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/custom.min.css') }}" id="custom-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/hms.css') }}">
    @stack('styles')
</head>

<body>
    {{-- Header & sidebar persist across wire:navigate visits (no flicker, no re-binding). --}}
    @persist('hms-header-'.$area)
        @include('layouts.partials.header', ['area' => $area])
    @endpersist

    @persist('hms-sidebar-'.$area)
        @include('layouts.partials.sidebar', ['area' => $area])
    @endpersist

    <main class="app-wrapper">
        <div class="app-container">
            @include('layouts.partials.impersonation-banner')
            @include('layouts.partials.demo-banner')
            {{ $slot }}
        </div>
    </main>

    @include('partials.scroll-to-top')

    <footer class="footer d-flex align-items-center text-center">
        <div class="container-fluid">
            <p class="mb-0">&copy; {{ date('Y') }} {{ $brand }} &middot; Powered by {{ config('app.name') }}</p>
        </div>
    </footer>

    @include('layouts.partials.flash')

    {{-- Vendor + theme scripts run once; hms.js re-initialises UI after every navigation. --}}
    <script data-navigate-once src="{{ asset_v('assets/js/sidebar.js') }}"></script>
    <script data-navigate-once src="{{ asset('assets/libs/choices.js/public/assets/scripts/choices.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('assets/libs/simplebar/simplebar.min.js') }}"></script>
    <script data-navigate-once src="{{ asset_v('assets/js/pages/scroll-top.init.js') }}"></script>
    <script data-navigate-once src="{{ asset('assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
    <script data-navigate-once src="{{ asset('assets/libs/apexcharts/apexcharts.min.js') }}"></script>
    <script data-navigate-once src="{{ asset_v('assets/js/app.js') }}"></script>
    <script data-navigate-once src="{{ asset_v('assets/js/hms.js') }}"></script>
    @stack('scripts')
</body>

</html>
