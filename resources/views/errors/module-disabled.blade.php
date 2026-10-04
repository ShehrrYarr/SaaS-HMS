@extends('errors.minimal-shell', ['title' => 'Module not available', 'icon' => 'ri-lock-line', 'color' => 'info'])
@section('body')
    <h4 class="mb-2">{{ $module }} is not in your plan</h4>
    <p class="text-muted mb-4">Ask your hospital administrator to upgrade the subscription to unlock this module.</p>
    <a href="{{ url()->previous() }}" class="btn btn-light">Go back</a>
    @can('subscription.manage')<a href="{{ route('tenant.subscription') }}" class="btn btn-primary ms-1">Subscription</a>@endcan
@endsection
