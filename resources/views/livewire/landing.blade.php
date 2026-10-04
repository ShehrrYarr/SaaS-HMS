<?php

use App\Models\Hospital;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Welcome')] class extends Component
{
    public string $hospital = '';

    public string $target = 'staff';

    public function go()
    {
        $this->validate(['hospital' => 'required|string|max:100'], [], ['hospital' => 'hospital code']);

        $value = trim(strtolower($this->hospital));
        $found = Hospital::where('slug', $value)->orWhere('code', strtoupper($value))->first();

        if (! $found) {
            $this->addError('hospital', 'No hospital found with that code.');

            return;
        }

        return $this->redirect(route($this->target === 'patient' ? 'portal.login' : 'tenant.login', ['hospital' => $found->slug]));
    }
}; ?>

<div>
    <div class="mb-5 text-center">
        <h4 class="fw-normal">Welcome to <span class="fw-bold text-primary">{{ config('app.name') }}</span></h4>
        <p class="text-muted mb-0">Enter your hospital code to continue.</p>
    </div>

    <form wire:submit="go" class="form-custom">
        <div class="btn-group w-100 mb-4" role="group">
            <input type="radio" class="btn-check" id="t-staff" value="staff" wire:model="target">
            <label class="btn btn-outline-primary" for="t-staff"><i class="ri-hospital-line me-1"></i> Staff</label>
            <input type="radio" class="btn-check" id="t-patient" value="patient" wire:model="target">
            <label class="btn btn-outline-primary" for="t-patient"><i class="ri-user-heart-line me-1"></i> Patient Portal</label>
        </div>

        <x-form.input label="Hospital code" model="hospital" placeholder="e.g. city-hospital or CH" required autofocus />

        <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="go">Continue</span>
            <span wire:loading wire:target="go">Please wait...</span>
        </button>
    </form>

    <div class="center-hr my-8 text-nowrap text-muted">Platform</div>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <a href="{{ route('admin.login') }}" class="btn btn-light-primary btn-sm"><i class="ri-shield-user-line me-1"></i>Super Admin Login</a>
        @if (config('hms.template_demo'))
            <a href="{{ url('template/index') }}" class="btn btn-light-secondary btn-sm" target="_blank"><i class="ri-layout-masonry-line me-1"></i>UI Template Reference</a>
        @endif
    </div>
</div>
