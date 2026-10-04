@if (session()->has('impersonator_id'))
    <div class="alert alert-warning d-flex align-items-center justify-content-between gap-3 py-2 mb-4">
        <span><i class="ri-spy-line me-1"></i> You are viewing this hospital as <strong>{{ auth()->user()?->name }}</strong> (Super Admin impersonation).</span>
        <form method="POST" action="{{ route('impersonate.leave') }}">
            @csrf
            <button class="btn btn-sm btn-warning">Return to Super Admin</button>
        </form>
    </div>
@endif
