<?php

use App\Models\OtpCode;
use App\Models\Patient;
use App\Models\User;
use App\Services\Sms\SmsManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Patient Portal')] class extends Component
{
    public string $mode = 'otp';

    // password mode
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    // otp mode
    public string $identifier = '';

    public string $code = '';

    public ?int $otpUserId = null;

    public ?string $maskedPhone = null;

    public function login()
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'portal-login:'.hospital()->id.'|'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! Auth::attempt(['hospital_id' => hospital()->id, 'email' => $this->email, 'password' => $this->password, 'status' => 'active'], $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();

        return $this->redirect(route('portal.dashboard'));
    }

    public function sendOtp(SmsManager $sms)
    {
        $this->validate(['identifier' => 'required|string|max:50'], [], ['identifier' => 'UHID or phone']);

        $key = 'portal-otp:'.hospital()->id.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('identifier', 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }
        RateLimiter::hit($key, 300);

        $value = trim($this->identifier);
        $patient = Patient::whereNotNull('user_id')->where(fn ($q) => $q->where('uhid', $value)->orWhere('phone', $value))->first();
        $user = $patient?->user;

        if (! $patient || ! $user || ! $user->isActive() || ! $patient->phone) {
            $this->addError('identifier', 'Portal access is not enabled for this record. Please contact the hospital reception.');

            return;
        }

        $code = (string) random_int(100000, 999999);
        OtpCode::where('user_id', $user->id)->whereNull('consumed_at')->delete();
        OtpCode::create(['user_id' => $user->id, 'code' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);

        $sms->send($patient->phone, "Your ".hospital()->name." portal code is {$code}. It expires in 10 minutes.");

        $this->otpUserId = $user->id;
        $this->maskedPhone = Str::mask($patient->phone, '*', 2, max(0, strlen($patient->phone) - 5));

        if (config('hms.sms_driver') === 'log' && config('app.debug')) {
            $this->dispatch('toast', type: 'info', message: "Dev mode (SMS log driver): your code is {$code}");
        }
    }

    public function verifyOtp()
    {
        $this->validate(['code' => 'required|digits:6']);

        $otp = OtpCode::where('user_id', $this->otpUserId)->whereNull('consumed_at')->latest()->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= 5) {
            $this->addError('code', $otp && $otp->attempts >= 5
                ? 'Too many incorrect codes. Please request a new one.'
                : 'This code has expired. Please request a new one.');

            return;
        }

        if (! Hash::check($this->code, $otp->code)) {
            $otp->increment('attempts');
            $this->addError('code', 'Incorrect code.');

            return;
        }

        $otp->update(['consumed_at' => now()]);
        Auth::login(User::where('hospital_id', hospital()->id)->findOrFail($this->otpUserId), true);
        session()->regenerate();

        return $this->redirect(route('portal.dashboard'));
    }

    public function resetOtp(): void
    {
        $this->reset('otpUserId', 'code', 'maskedPhone');
    }
}; ?>

<div>
    <div class="mb-5 text-center">
        <img src="{{ hospital()->logoUrl() }}" alt="{{ hospital()->name }}" class="hms-logo mb-4" height="40">
        <h4 class="fw-normal"><span class="fw-bold text-primary">Patient</span> Portal</h4>
        <p class="text-muted mb-0">View appointments, lab reports, prescriptions and bills.</p>
    </div>

    <ul class="nav nav-pills nav-justified mb-4">
        <li class="nav-item"><button type="button" class="nav-link w-100 {{ $mode === 'otp' ? 'active' : '' }}" wire:click="$set('mode', 'otp')">Phone / UHID + OTP</button></li>
        <li class="nav-item"><button type="button" class="nav-link w-100 {{ $mode === 'password' ? 'active' : '' }}" wire:click="$set('mode', 'password')">Email &amp; Password</button></li>
    </ul>

    @if ($mode === 'password')
        @include('livewire.auth.partials.password-form')
    @elseif (! $otpUserId)
        <form wire:submit="sendOtp" class="form-custom">
            <x-form.input label="UHID or registered phone number" model="identifier" placeholder="e.g. CH-26-000001 or +1 555 0100" required autofocus />
            <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sendOtp">Send one-time code</span>
                <span wire:loading wire:target="sendOtp">Sending...</span>
            </button>
        </form>
    @else
        <form wire:submit="verifyOtp" class="form-custom">
            <div class="alert alert-info py-2">We sent a 6-digit code to <strong>{{ $maskedPhone }}</strong>.</div>
            <x-form.input label="Verification code" model="code" inputmode="numeric" maxlength="6" placeholder="••••••" required autofocus />
            <button type="submit" class="btn btn-primary w-100 mb-2">Verify &amp; Sign in</button>
            <button type="button" class="btn btn-link w-100" wire:click="resetOtp">Use a different number</button>
        </form>
    @endif

    @include('livewire.auth.partials.demo-buttons', ['portal' => true])

    <p class="mb-0 mt-5 text-muted text-center">Hospital staff? <a href="{{ route('tenant.login') }}" class="text-primary fw-semibold">Staff sign in</a></p>
</div>
