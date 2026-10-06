<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-100">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' | ' : '' }}{{ hospital()?->name ?? config('app.name') }}</title>
    <link rel="shortcut icon" href="{{ asset('assets/images/hms-mark.png') }}">
    <script src="{{ asset_v('assets/js/layout/layout-auth.js') }}"></script>
    <script src="{{ asset_v('assets/js/layout/layout.js') }}"></script>
    <link rel="stylesheet" href="{{ asset_v('assets/css/icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ asset_v('assets/css/bootstrap.min.css') }}" id="bootstrap-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/app.min.css') }}" id="app-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/custom.min.css') }}" id="custom-style">
    <link rel="stylesheet" href="{{ asset_v('assets/css/hms.css') }}">
</head>

<body>
    <div class="account-pages">
        <img src="{{ asset('assets/images/auth/auth_bg.jpeg') }}" alt="" class="auth-bg light">
        <img src="{{ asset('assets/images/auth/auth_bg_dark.jpg') }}" alt="" class="auth-bg dark">
        <div class="container">
            <div class="justify-content-center row gy-0">
                <div class="col-lg-6 auth-banners">
                    <div class="bg-login card card-body m-0 h-100 border-0">
                        <img src="{{ asset('assets/images/auth/bg-img-2.png') }}" class="img-fluid auth-banner" alt="">
                        <div class="auth-contain">
                            <div class="text-center text-white my-4 p-4">
                                @if (hospital())
                                    <h3 class="text-white">{{ hospital()->name }}</h3>
                                    <p class="mt-3 mb-0">{{ hospital()->fullAddress() }}</p>
                                @else
                                    <h3 class="text-white">{{ config('app.name') }}</h3>
                                    <p class="mt-3 mb-0">Cloud hospital management: patients, OPD, IPD, pharmacy, laboratory, billing and more for every hospital on one secure platform.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="auth-box card card-body m-0 h-100 border-0 justify-content-center">
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('layouts.partials.flash')
    <script src="{{ asset('assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
    <script src="{{ asset_v('assets/js/hms.js') }}"></script>
</body>

</html>
