<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Staff Sign In')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $hospital = hospital();
        $key = 'login:'.$hospital->id.'|'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $credentials = ['hospital_id' => $hospital->id, 'email' => $this->email, 'password' => $this->password, 'status' => 'active'];
        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirect(Auth::user()->homeUrl());
    }
}; ?>

<div>
    <div class="mb-5 text-center">
        <img src="{{ hospital()->logoUrl() }}" alt="{{ hospital()->name }}" class="hms-logo mb-4" height="40">
        <h4 class="fw-normal">Welcome to <span class="fw-bold text-primary">{{ hospital()->name }}</span></h4>
        <p class="text-muted mb-0">Staff sign in</p>
    </div>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @include('livewire.auth.partials.password-form')

    <p class="mb-0 mt-5 text-muted text-center">
        Are you a patient? <a href="{{ route('portal.login') }}" class="text-primary fw-semibold">Open the Patient Portal</a>
    </p>
</div>
