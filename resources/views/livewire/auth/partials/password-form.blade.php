<form wire:submit="login" class="form-custom">
    <x-form.input label="Email" model="email" type="email" placeholder="Enter your email" required autofocus autocomplete="username" />

    <div class="mb-4" x-data="{ show: false }">
        <label class="form-label" for="password">Password<span class="text-danger ms-1">*</span></label>
        <div class="input-group has-validation">
            <input :type="show ? 'text' : 'password'" id="password" wire:model="password" autocomplete="current-password"
                class="form-control @error('password') is-invalid @enderror" placeholder="Enter your password">
            <button type="button" class="input-group-text bg-transparent" x-on:click="show = !show" tabindex="-1">
                <i class="text-muted" :class="show ? 'ri-eye-line' : 'ri-eye-off-line'"></i>
            </button>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="form-check form-check-sm d-flex align-items-center gap-2 mb-5">
        <input class="form-check-input" type="checkbox" id="remember" wire:model="remember">
        <label class="form-check-label" for="remember">Remember me</label>
    </div>

    <button type="submit" class="btn btn-primary rounded-2 w-100" wire:loading.attr="disabled">
        <span wire:loading.remove wire:target="login">Sign In</span>
        <span wire:loading wire:target="login"><span class="spinner-border spinner-border-sm me-1"></span> Please wait...</span>
    </button>
</form>
