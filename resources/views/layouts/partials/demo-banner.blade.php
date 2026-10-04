@if (is_demo_hospital() && auth()->check())
    @php
        $hours = max(1, (int) config('hms.demo.reset_every_hours'));
        $nextReset = now()->startOfDay()->addHours((intdiv(now()->hour, $hours) + 1) * $hours);
        $accounts = config('hms.demo.accounts');
        $current = collect($accounts)->search(fn ($a) => $a['email'] === auth()->user()->email);
    @endphp
    <div class="alert alert-primary d-flex flex-wrap align-items-center gap-2 py-2 mb-4" role="status">
        <i class="ri-flask-line fs-5"></i>
        <span class="me-auto">
            <strong>Live demo</strong> · signed in as <strong>{{ $accounts[$current]['label'] ?? auth()->user()->roleLabel() }}</strong>.
            Explore freely — data resets at {{ $nextReset->format('H:i') }} ({{ $nextReset->diffForHumans() }}).
        </span>
        <div class="dropdown">
            <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ri-user-shared-line me-1"></i>Switch role
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                @foreach ($accounts as $key => $account)
                    <form method="POST" action="{{ route('demo.login', $key) }}">
                        @csrf
                        <button class="dropdown-item d-flex align-items-center gap-2 {{ $key === $current ? 'active' : '' }}">
                            <i class="{{ $account['icon'] }}"></i>{{ $account['label'] }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
        <a href="{{ route('home') }}" class="btn btn-sm btn-light">Back to website</a>
    </div>
@endif
