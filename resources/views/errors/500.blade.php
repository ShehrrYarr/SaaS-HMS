@extends('errors.minimal-shell', ['title' => '500', 'icon' => 'ri-error-warning-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">500</h1>
    {{-- A crash arrives here wrapping the original exception, whose message can hold SQL or file paths: never show that. --}}
    <p class="text-muted mb-4">{{ (! $exception?->getPrevious() ? $exception?->getMessage() : '') ?: 'Something went wrong on our side. Please try again; if it keeps happening, tell your administrator.' }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">{{ auth()->check() ? 'Back to dashboard' : 'Go to the home page' }}</a>
@endsection
