@extends('errors.minimal-shell', ['title' => 'Signed in elsewhere', 'icon' => 'ri-user-shared-line', 'color' => 'info'])
@section('body')
    @php
        $area = $user->is_super_admin ? 'the Super Admin console' : ($user->hospital?->name ?? 'another hospital');
    @endphp
    <h4 class="mb-2">You're signed in to {{ $area }}</h4>
    <p class="text-muted mb-4">
        {{ $hospital->name }} uses its own accounts. Go back, or sign out of {{ $user->name }} to sign in here.
    </p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ $user->homeUrl() }}" class="btn btn-primary">Back to {{ $user->is_super_admin ? 'Super Admin' : 'my dashboard' }}</a>
        <form method="POST" action="{{ route('tenant.logout', ['hospital' => $hospital->slug]) }}">
            @csrf
            <button class="btn btn-light">Sign out and continue to {{ $hospital->name }}</button>
        </form>
    </div>
@endsection
