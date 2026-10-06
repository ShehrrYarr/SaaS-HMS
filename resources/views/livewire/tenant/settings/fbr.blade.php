<?php

use App\Livewire\Concerns\GuardsDemo;
use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('FBR Access')] class extends Component
{
    use GuardsDemo, Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    protected function accounts()
    {
        return User::forCurrentHospital()->whereHas('roles', fn ($r) => $r->where('name', 'FBR Officer'));
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'email' => '', 'phone' => '', 'password' => '', 'status' => 'active'];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $u = $this->accounts()->findOrFail($id);
        $this->editingId = $id;
        $this->form = ['name' => $u->name, 'email' => $u->email, 'phone' => (string) $u->phone, 'password' => '', 'status' => $u->status];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('users.manage');
        $hid = hospital()->id;
        $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => ['required', 'email', Rule::unique('users', 'email')->where('hospital_id', $hid)->ignore($this->editingId)],
            'form.phone' => 'nullable|string|max:30',
            'form.password' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'min:8'],
            'form.status' => 'required|in:active,inactive',
        ]);

        $user = $this->editingId ? $this->accounts()->findOrFail($this->editingId) : new User;
        if ($user->exists && $user->isDemoAccount() && $this->demoLocked('Editing the shared demo accounts')) {
            return;
        }
        $user->fill(collect($this->form)->only(['name', 'email', 'phone', 'status'])->all());
        if ($this->form['password']) {
            $user->password = $this->form['password'];
        }
        $user->hospital_id = $hid;
        $isNew = ! $user->exists;
        $user->save();
        // FBR accounts hold this one role only, so they can never reach the staff screens.
        $user->syncRoles(['FBR Officer']);
        if ($isNew) {
            AuditLog::record('fbr_account_created', $user, [], ['email' => $user->email], "FBR account created for {$user->name}");
        }

        $this->showForm = false;
        $this->toast('FBR account saved.');
    }

    public function with(): array
    {
        return [
            'users' => $this->accounts()->orderBy('name')->get(),
            'activity' => AuditLog::with('user')->whereIn('event', ['fbr_viewed', 'fbr_exported', 'fbr_account_created'])->latest('created_at')->latest('id')->limit(20)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="FBR Access" subtitle="Read-only patient visit register for the tax authority">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-user-add-line me-1"></i>New FBR account</button>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">FBR accounts</h5></div>
                <div class="card-body border-bottom">
                    <p class="text-muted fs-13 mb-2">FBR officers sign in at this address. They can only see the patient visit register (date filter, CSV and PDF download); they cannot open any other screen or change anything.</p>
                    <div class="input-group input-group-sm" x-data="{ copied: false }">
                        <input type="text" class="form-control" value="{{ route('fbr.login') }}" readonly aria-label="FBR login address">
                        <button class="btn btn-light-primary" type="button" x-on:click="navigator.clipboard.writeText('{{ route('fbr.login') }}'); copied = true; setTimeout(() => copied = false, 1500)">
                            <i class="ri-file-copy-line me-1"></i><span x-text="copied ? 'Copied' : 'Copy'"></span>
                        </button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hms table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Account</th><th>Phone</th><th>Last login</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($users as $u)
                                <tr wire:key="fbr-{{ $u->id }}">
                                    <td><strong>{{ $u->name }}</strong><div class="fs-12 text-muted">{{ $u->email }}</div></td>
                                    <td class="fs-13">{{ $u->phone ?: '—' }}</td>
                                    <td class="fs-13">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
                                    <td><x-status :value="$u->status" /></td>
                                    <td class="text-end"><button title="Edit" aria-label="Edit" class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $u->id }})"><i class="ri-edit-line"></i></button></td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="5" message="No FBR accounts yet." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Recent FBR activity</h5></div>
                <ul class="list-group list-group-flush fs-13">
                    @forelse ($activity as $log)
                        <li class="list-group-item">
                            <div>{{ $log->description }}</div>
                            <div class="text-muted fs-12">{{ $log->user?->name ?? 'System' }} · {{ fmt_datetime($log->created_at) }} · {{ $log->ip_address }}</div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted text-center py-4">No FBR activity yet.</li>
                    @endforelse
                </ul>
                @can('audit.view')
                    <div class="card-footer fs-13"><a href="{{ route('tenant.settings.audit') }}" wire:navigate>Full audit log <i class="ri-arrow-right-line"></i></a></div>
                @endcan
            </div>
        </div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit FBR account' : 'New FBR account'">
        <x-form.input label="Officer name" model="form.name" required />
        <div class="row">
            <x-form.input class="col-md-6" label="Email (login)" model="form.email" type="email" required />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-md-6" label="Password" model="form.password" type="password" :hint="$editingId ? 'Leave blank to keep current password.' : 'At least 8 characters. Share it with the officer securely.'" />
            <x-form.select class="col-md-6" label="Status" model="form.status" :options="['active' => 'Active', 'inactive' => 'Inactive (cannot sign in)']" :placeholder="false" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
