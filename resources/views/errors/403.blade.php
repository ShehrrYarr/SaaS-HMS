@extends('errors.minimal-shell', ['title' => '403', 'icon' => 'ri-error-warning-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">403</h1>
    <p class="text-muted mb-4">{{ $exception?->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[403] }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">{{ auth()->check() ? 'Back to dashboard' : 'Go to the home page' }}</a>
@endsection
