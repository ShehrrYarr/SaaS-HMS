<?php

use App\Support\LoginThrottle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Super Admin Sign In')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'admin-login:'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5) || LoginThrottle::blocked()) {
            $this->addError('email', 'Too many attempts. Try again in '.max(RateLimiter::availableIn($key), LoginThrottle::retryAfter()).' seconds.');

            return;
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_super_admin' => true, 'status' => 'active'], $this->remember)) {
            RateLimiter::hit($key, 60);
            LoginThrottle::failed();
            $this->addError('email', 'These credentials do not match a platform administrator.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirect(route('admin.dashboard'));
    }
}; ?>

<div>
    <div class="mb-5 text-center">
        <span class="badge bg-primary-subtle text-primary mb-3">Platform Console</span>
        <h4 class="fw-normal">Super Admin <span class="fw-bold text-primary">Sign In</span></h4>
        <p class="text-muted mb-0">Manage hospitals, subscriptions and platform settings.</p>
    </div>

    @include('livewire.auth.partials.password-form')

    <p class="mb-0 mt-5 text-muted text-center"><a href="{{ route('home') }}" class="text-primary">&larr; Back to hospital login</a></p>
</div>
