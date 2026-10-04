@extends('errors.minimal-shell', ['title' => 'Account suspended', 'icon' => 'ri-pause-circle-line', 'color' => 'warning'])
@section('body')
    <h4 class="mb-2">{{ $hospital->name }} is suspended</h4>
    <p class="text-muted">{{ $hospital->suspended_reason ?: 'This hospital account is currently suspended.' }}</p>
    <p class="text-muted mb-4">Hospital administrators can still sign in to review and pay subscription invoices.</p>
    <div class="d-flex justify-content-center gap-2">
        @auth
            @can('subscription.manage')
                <a href="{{ route('tenant.subscription') }}" class="btn btn-primary">View subscription</a>
            @endcan
            <form method="POST" action="{{ route('tenant.logout') }}">@csrf<button class="btn btn-light">Sign out</button></form>
        @else
            <a href="{{ route('tenant.login') }}" class="btn btn-primary">Admin sign in</a>
        @endauth
    </div>
@endsection
