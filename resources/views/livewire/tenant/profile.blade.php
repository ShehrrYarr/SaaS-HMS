<?php

use App\Livewire\Concerns\Toasts;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('My Profile')] class extends Component
{
    use Toasts, WithFileUploads;

    public string $name = '';

    public string $email = '';

    public ?string $phone = null;

    public $avatar;

    public $signature;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone;
    }

    public function save(): void
    {
        $user = auth()->user();
        $data = $this->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', Rule::unique('users')->where('hospital_id', $user->hospital_id)->ignore($user->id)],
            'phone' => 'nullable|string|max:30',
            'avatar' => 'nullable|image|max:2048',
            'signature' => 'nullable|image|max:1024',
        ]);

        $folder = hospital()->storagePath('users/'.$user->id);
        if ($this->avatar) {
            $data['avatar_path'] = $this->avatar->store($folder, 'local');
        }
        if ($this->signature) {
            $data['signature_path'] = $this->signature->store($folder, 'local');
        }
        unset($data['avatar'], $data['signature']);

        $user->update($data);
        $user->staff?->update(['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone]);
        $this->reset('avatar', 'signature');
        $this->toast('Profile updated.');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);
        auth()->user()->update(['password' => $this->password]);
        $this->reset('current_password', 'password', 'password_confirmation');
        $this->toast('Password changed.');
    }
}; ?>

<div>
    <x-page-header title="My Profile" subtitle="Account" />
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Account details</h5></div>
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-4">
                        <x-avatar :src="$avatar ? $avatar->temporaryUrl() : auth()->user()->avatarUrl()" :name="$name" size="lg" />
                        <div>
                            <h5 class="mb-0">{{ $name }}</h5>
                            <span class="text-muted">{{ auth()->user()->roleLabel() }} &middot; {{ hospital()->name }}</span>
                        </div>
                    </div>
                    <div class="row">
                        <x-form.input class="col-md-6" label="Full name" model="name" required />
                        <x-form.input class="col-md-6" label="Email (login)" model="email" type="email" required />
                        <x-form.input class="col-md-6" label="Phone" model="phone" />
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Profile photo</label>
                            <input type="file" class="form-control @error('avatar') is-invalid @enderror" wire:model="avatar" accept="image/*">
                            @error('avatar')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Signature (for reports &amp; prescriptions)</label>
                            <input type="file" class="form-control @error('signature') is-invalid @enderror" wire:model="signature" accept="image/*">
                            @error('signature')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @if (auth()->user()->signature_path)
                                <img src="{{ route('files.show', ['path' => auth()->user()->signature_path]) }}" class="mt-2 border rounded p-1" height="50" alt="signature">
                            @endif
                        </div>
                    </div>
                    <button class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save profile</button>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-0">
                <div class="card-header"><h5 class="card-title mb-0">Change password</h5></div>
                <div class="card-body">
                    <x-form.input label="Current password" model="current_password" type="password" />
                    <x-form.input label="New password" model="password" type="password" />
                    <x-form.input label="Confirm new password" model="password_confirmation" type="password" />
                    <button class="btn btn-light-primary" wire:click="updatePassword">Update password</button>
                </div>
            </div>
        </div>
    </div>
</div>
