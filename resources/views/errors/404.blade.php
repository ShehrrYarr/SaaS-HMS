@extends('errors.minimal-shell', ['title' => '404', 'icon' => 'ri-compass-discover-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">404</h1>
    @php
        // Only show messages written for people (abort(404, '...')); framework ones name models, IDs and routes.
        $message = $exception?->getPrevious() || str_starts_with((string) $exception?->getMessage(), 'The route ') ? '' : $exception?->getMessage();
    @endphp
    <p class="text-muted mb-4">{{ $message ?: "We couldn't find that page. It may have been removed, or the link may be out of date." }}</p>
    <a href="{{ auth()->user()?->homeUrl() ?? url('/') }}" class="btn btn-primary">{{ auth()->check() ? 'Back to dashboard' : 'Go to the home page' }}</a>
@endsection
