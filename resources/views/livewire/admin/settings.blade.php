<?php

use App\Livewire\Concerns\Toasts;
use App\Models\PlatformSetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Global Settings')] class extends Component
{
    use Toasts;

    public array $settings = [];

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    protected array $keys = ['platform_name', 'support_email', 'support_phone', 'bank_details', 'invoice_tax_percent', 'invoice_footer', 'suspension_grace_days'];

    public function mount(): void
    {
        foreach ($this->keys as $key) {
            $this->settings[$key] = platform_setting($key, $key === 'suspension_grace_days' ? config('hms.suspension_grace_days') : '');
        }
    }

    public function save(): void
    {
        $this->validate([
            'settings.platform_name' => 'required|string|max:100',
            'settings.support_email' => 'nullable|email',
            'settings.support_phone' => 'nullable|string|max:30',
            'settings.bank_details' => 'nullable|string|max:1000',
            'settings.invoice_tax_percent' => 'nullable|numeric|min:0|max:100',
            'settings.invoice_footer' => 'nullable|string|max:255',
            'settings.suspension_grace_days' => 'required|integer|min:0|max:90',
        ]);

        PlatformSetting::put($this->settings);
        $this->toast('Settings saved.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|string|min:8|confirmed',
        ]);
        auth()->user()->update(['password' => $this->new_password]);
        $this->reset('current_password', 'new_password', 'new_password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="Global Settings" subtitle="Settings" :breadcrumbs="['Platform' => route('admin.dashboard')]" />

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Platform</h5></div>
                <div class="card-body row">
                    <x-form.input class="col-md-6" label="Platform name" model="settings.platform_name" required />
                    <x-form.input class="col-md-6" label="Support email" model="settings.support_email" type="email" />
                    <x-form.input class="col-md-6" label="Support phone" model="settings.support_phone" />
                    <x-form.input class="col-md-3" label="Invoice tax %" model="settings.invoice_tax_percent" type="number" step="0.01" />
                    <x-form.input class="col-md-3" label="Suspension grace (days)" model="settings.suspension_grace_days" type="number" />
                    <x-form.textarea class="col-12" label="Bank / payment instructions (shown on invoices)" model="settings.bank_details" rows="4" />
                    <x-form.input class="col-12" label="Invoice footer" model="settings.invoice_footer" />
                    <div class="col-12"><button class="btn btn-primary" wire:click="save">Save settings</button></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Change my password</h5></div>
                <div class="card-body">
                    <x-form.input label="Current password" model="current_password" type="password" />
                    <x-form.input label="New password" model="new_password" type="password" />
                    <x-form.input label="Confirm new password" model="new_password_confirmation" type="password" />
                    <button class="btn btn-light-primary" wire:click="changePassword">Update password</button>
                </div>
            </div>
        </div>
    </div>
</div>
