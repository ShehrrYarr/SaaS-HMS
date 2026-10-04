@extends('errors.minimal-shell', ['title' => '419', 'icon' => 'ri-error-warning-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">419</h1>
    <p class="text-muted mb-4">{{ $exception?->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[419] }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">Back to dashboard</a>
@endsection
