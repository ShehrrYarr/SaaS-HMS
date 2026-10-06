{{-- Shown on the demo hospital's login pages: sign in without a password. $for = staff | portal | fbr --}}
@if (is_demo_hospital())
    @php($for = $for ?? 'staff')
    <div class="center-hr my-6 text-nowrap text-muted">Live demo — no password needed</div>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        @foreach (config('hms.demo.accounts') as $key => $account)
            @if ($for === match ($key) { 'patient' => 'portal', 'fbr' => 'fbr', default => 'staff' })
                <form method="POST" action="{{ route('demo.login', $key) }}">
                    @csrf
                    <button class="btn btn-sm btn-light-{{ $account['color'] }}"><i class="{{ $account['icon'] }} me-1"></i>{{ $account['label'] }}</button>
                </form>
            @endif
        @endforeach
    </div>
@endif
