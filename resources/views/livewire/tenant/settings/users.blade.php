<?php

use App\Livewire\Concerns\GuardsDemo;
use App\Livewire\Concerns\WithTable;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] #[Title('Users')] class extends Component
{
    use GuardsDemo, WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'last_login_at', 'created_at'];

    #[Url]
    public string $role = '';

    #[Url]
    public bool $patients = false;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'email' => '', 'phone' => '', 'password' => '', 'roles' => [], 'status' => 'active'];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $u = User::forCurrentHospital()->findOrFail($id);
        $this->editingId = $id;
        $this->form = ['name' => $u->name, 'email' => $u->email, 'phone' => (string) $u->phone, 'password' => '', 'roles' => $u->getRoleNames()->all(), 'status' => $u->status];
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
            'form.roles' => 'required|array|min:1',
            'form.roles.*' => Rule::exists('roles', 'name')->where('hospital_id', $hid),
            'form.status' => 'required|in:active,inactive',
        ]);

        $user = $this->editingId ? User::forCurrentHospital()->findOrFail($this->editingId) : new User;
        if ($user->exists && $user->isDemoAccount() && $this->demoLocked('Editing the shared demo accounts')) {
            return;
        }
        if ($user->id === auth()->id() && (! in_array('Hospital Admin', $this->form['roles']) && $user->hasRole('Hospital Admin') || $this->form['status'] !== 'active')) {
            $this->addError('form.roles', 'You cannot remove your own admin access or deactivate yourself.');

            return;
        }
        $user->fill(collect($this->form)->only(['name', 'email', 'phone', 'status'])->all());
        if ($this->form['password']) {
            $user->password = $this->form['password'];
        }
        $user->hospital_id = $hid;
        $user->save();
        $old = $user->getRoleNames()->all();
        $user->syncRoles($this->form['roles']);
        if ($old !== $this->form['roles']) {
            AuditLog::record('roles_changed', $user, ['roles' => $old], ['roles' => $this->form['roles']], "Roles of {$user->name} changed");
        }
        $user->staff?->update(['name' => $user->name, 'email' => $user->email]);

        $this->showForm = false;
        $this->toast('User saved.');
    }

    public function with(): array
    {
        $query = User::forCurrentHospital()->with(['roles', 'staff'])
            ->when(! $this->patients, fn ($q) => $q->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Patient')))
            ->when($this->patients, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', 'Patient')))
            ->when($this->role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $this->role)))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")));

        return [
            'users' => $this->applySort($query)->paginate($this->perPage),
            'roles' => Role::where('hospital_id', hospital()->id)->orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Users" subtitle="Login accounts">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-user-add-line me-1"></i>New user</button>
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Name or email...">
            <select class="form-select w-auto" wire:model.live="role"><option value="">All roles</option>@foreach ($roles as $r)<option>{{ $r }}</option>@endforeach</select>
            <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="pt" wire:model.live="patients"><label class="form-check-label" for="pt">Portal (patient) accounts</label></div>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><x-th field="name" :sort="$sortField" :dir="$sortDirection">User</x-th><th>Roles</th><th>Staff record</th><x-th field="last_login_at" :sort="$sortField" :dir="$sortDirection">Last login</x-th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr wire:key="u-{{ $u->id }}">
                            <td><div class="d-flex align-items-center gap-2"><x-avatar :src="$u->avatarUrl()" :name="$u->name" size="sm" /><div><strong>{{ $u->name }}</strong><div class="fs-12 text-muted">{{ $u->email }}</div></div></div></td>
                            <td>@foreach ($u->roles as $r)<span class="badge bg-primary-subtle text-primary me-1">{{ $r->name }}</span>@endforeach</td>
                            <td class="fs-13">{{ $u->staff?->employee_code ?? '—' }}</td>
                            <td class="fs-13">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
                            <td><x-status :value="$u->status" /></td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $u->id }})"><i class="ri-edit-line"></i></button></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="6" message="No users." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $users->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit user' : 'New user'">
        <x-form.input label="Name" model="form.name" required />
        <div class="row">
            <x-form.input class="col-md-6" label="Email (login)" model="form.email" type="email" required />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-md-6" label="Password" model="form.password" type="password" :hint="$editingId ? 'Leave blank to keep current password.' : null" />
            <x-form.select class="col-md-6" label="Status" model="form.status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :placeholder="false" />
        </div>
        <label class="form-label">Roles <span class="text-danger">*</span></label>
        <div class="row">
            @foreach ($roles as $r)
                <div class="col-md-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="r-{{ \Illuminate\Support\Str::slug($r) }}" value="{{ $r }}" wire:model="form.roles"><label class="form-check-label" for="r-{{ \Illuminate\Support\Str::slug($r) }}">{{ $r }}</label></div></div>
            @endforeach
        </div>
        @error('form.roles')<div class="text-danger fs-12 mt-1">{{ $message }}</div>@enderror
        <p class="fs-12 text-muted mt-2 mb-0">Tip: create the employee in HR → Staff to track payroll; you can create the login from there too.</p>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
