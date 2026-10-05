@extends('errors.minimal-shell', ['title' => '419', 'icon' => 'ri-time-line'])
@section('body')
    <h1 class="display-5 fw-bold mb-1">Page expired</h1>
    {{-- Usually a form left open past the session lifetime; the raw "CSRF token mismatch" means nothing to people. --}}
    <p class="text-muted mb-4">This page was open for too long, so it could not be sent. Go back, and the page will reload so you can try again.</p>
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : (auth()->user()?->homeUrl() ?? url('/')) }}" class="btn btn-primary">Go back and try again</a>
@endsection
