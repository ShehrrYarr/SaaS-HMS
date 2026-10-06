<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('FBR Portal')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'fbr-login:'.hospital()->id.'|'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        // Only FBR accounts sign in here; staff and patients have their own pages.
        $user = User::where('hospital_id', hospital()->id)->where('email', $this->email)->where('status', 'active')->first();
        if (! $user?->isFbrOfficer() || ! Auth::attempt(['hospital_id' => hospital()->id, 'email' => $this->email, 'password' => $this->password, 'status' => 'active'], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match an FBR account at this hospital.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirect(route('fbr.dashboard'));
    }
}; ?>

<div>
    <div class="mb-5 text-center">
        <img src="{{ hospital()->logoUrl() }}" alt="{{ hospital()->name }}" class="hms-logo mb-4" height="40">
        <h4 class="fw-normal"><span class="fw-bold text-primary">FBR</span> Portal</h4>
        <p class="text-muted mb-0">Read-only patient visit register for {{ hospital()->name }}.</p>
    </div>

    @include('livewire.auth.partials.password-form')
    @include('livewire.auth.partials.demo-buttons', ['for' => 'fbr'])

    <p class="mb-0 mt-5 text-muted text-center">Hospital staff? <a href="{{ route('tenant.login') }}" class="text-primary fw-semibold">Staff sign in</a></p>
</div>
