<?php

use App\Livewire\Concerns\Toasts;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Profile')] class extends Component
{
    use Toasts;

    public array $form = [];

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $p = auth()->user()->patient;
        $this->form = collect($p->only(['phone', 'address', 'city', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation']))->map(fn ($v) => (string) $v)->all();
    }

    public function save(): void
    {
        $data = $this->validate([
            'form.phone' => 'nullable|string|max:30',
            'form.address' => 'nullable|string|max:255',
            'form.city' => 'nullable|string|max:100',
            'form.emergency_contact_name' => 'nullable|string|max:120',
            'form.emergency_contact_phone' => 'nullable|string|max:30',
            'form.emergency_contact_relation' => 'nullable|string|max:50',
        ])['form'];
        auth()->user()->patient->update(array_map(fn ($v) => $v === '' ? null : $v, $data));
        auth()->user()->update(['phone' => $data['phone'] ?: null]);
        $this->toast('Details updated.');
    }

    public function updatePassword(): void
    {
        $this->validate(['current_password' => 'required|current_password', 'password' => 'required|min:8|confirmed']);
        auth()->user()->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="My Profile" subtitle="Contact details" />
    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-0">
                <div class="card-body">
                    @php $p = auth()->user()->patient; @endphp
                    <p class="text-muted fs-13">Name, date of birth and identity details can only be changed at the hospital reception.</p>
                    <dl class="row fs-13">
                        <dt class="col-4">Name</dt><dd class="col-8">{{ $p->full_name }}</dd>
                        <dt class="col-4">UHID</dt><dd class="col-8">{{ $p->uhid }}</dd>
                        <dt class="col-4">Date of birth</dt><dd class="col-8">{{ fmt_date($p->date_of_birth) }}</dd>
                        <dt class="col-4">Email (login)</dt><dd class="col-8">{{ auth()->user()->email }}</dd>
                    </dl>
                    <div class="row">
                        <x-form.input class="col-md-6" label="Phone" model="form.phone" />
                        <x-form.input class="col-md-6" label="City" model="form.city" />
                        <x-form.input class="col-12" label="Address" model="form.address" />
                        <x-form.input class="col-md-5" label="Emergency contact" model="form.emergency_contact_name" />
                        <x-form.input class="col-md-4" label="Emergency phone" model="form.emergency_contact_phone" />
                        <x-form.input class="col-md-3" label="Relation" model="form.emergency_contact_relation" />
                    </div>
                    <button class="btn btn-primary" wire:click="save">Save</button>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Password</h6></div>
                <div class="card-body">
                    <x-form.input label="Current password" model="current_password" type="password" />
                    <x-form.input label="New password" model="password" type="password" />
                    <x-form.input label="Confirm" model="password_confirmation" type="password" />
                    <button class="btn btn-light-primary" wire:click="updatePassword">Change password</button>
                </div>
            </div>
        </div>
    </div>
</div>
