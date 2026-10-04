@extends('errors.minimal-shell', ['title' => '500', 'icon' => 'ri-error-warning-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">500</h1>
    <p class="text-muted mb-4">{{ $exception?->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[500] }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">Back to dashboard</a>
@endsection
