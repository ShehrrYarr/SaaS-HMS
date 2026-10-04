@extends('errors.minimal-shell', ['title' => '404', 'icon' => 'ri-compass-discover-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">404</h1>
    <p class="text-muted mb-4">{{ $exception?->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[404] }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">Back to dashboard</a>
@endsection
