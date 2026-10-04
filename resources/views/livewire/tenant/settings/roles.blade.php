<?php

use App\Livewire\Concerns\Toasts;
use App\Models\AuditLog;
use App\Services\HospitalProvisioner;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] #[Title('Roles & Permissions')] class extends Component
{
    use Toasts;

    #[Url]
    public ?int $roleId = null;

    public array $selected = [];

    public string $newRole = '';

    public string $copyFrom = '';

    public string $rename = '';

    public function mount(): void
    {
        $this->roleId ??= Role::where('hospital_id', hospital()->id)->orderBy('name')->value('id');
        $this->loadRole();
    }

    public function updatedRoleId(): void
    {
        $this->loadRole();
    }

    protected function role(): Role
    {
        return Role::where('hospital_id', hospital()->id)->findOrFail($this->roleId);
    }

    protected function loadRole(): void
    {
        if (! $this->roleId) {
            return;
        }
        $role = $this->role();
        $this->selected = $role->permissions->pluck('name')->all();
        $this->rename = $role->name;
    }

    public function toggleModule(string $module): void
    {
        $perms = array_keys(config("hms.modules.{$module}.permissions", []));
        $allOn = empty(array_diff($perms, $this->selected));
        $this->selected = $allOn ? array_values(array_diff($this->selected, $perms)) : array_values(array_unique(array_merge($this->selected, $perms)));
    }

    public function save(): void
    {
        $this->authorize('roles.manage');
        $role = $this->role();
        if ($role->name === 'Hospital Admin') {
            $this->toast('Hospital Admin always has every permission in your plan.', 'info');

            return;
        }
        $available = hospital()->availablePermissions();
        $grant = array_values(array_intersect($this->selected, $available));
        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($grant);

        if (! in_array($role->name, config('hms.protected_roles')) && $this->rename && $this->rename !== $role->name) {
            $this->validate(['rename' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('hospital_id', hospital()->id)->ignore($role->id)]]);
            $role->update(['name' => $this->rename]);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        AuditLog::record('role_permissions_changed', null, ['permissions' => $before], ['permissions' => collect($grant)->sort()->values()->all()], "Permissions of role {$role->name} updated");
        $this->toast('Role saved.');
    }

    public function create(): void
    {
        $this->authorize('roles.manage');
        $this->validate(['newRole' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->where('hospital_id', hospital()->id)]], [], ['newRole' => 'role name']);
        $role = Role::create(['name' => $this->newRole, 'guard_name' => 'web', 'hospital_id' => hospital()->id]);
        if ($this->copyFrom) {
            $role->syncPermissions(Role::where('hospital_id', hospital()->id)->findOrFail($this->copyFrom)->permissions);
        }
        $this->reset('newRole', 'copyFrom');
        $this->roleId = $role->id;
        $this->loadRole();
        $this->toast("Role {$role->name} created.");
    }

    public function delete(): void
    {
        $this->authorize('roles.manage');
        $role = $this->role();
        abort_if(in_array($role->name, config('hms.protected_roles')), 422);
        if ($role->users()->count() > 0) {
            $this->toast('Reassign users before deleting this role.', 'error');

            return;
        }
        $role->delete();
        $this->roleId = Role::where('hospital_id', hospital()->id)->orderBy('name')->value('id');
        $this->loadRole();
        $this->toast('Role deleted.', 'warning');
    }

    public function restoreDefaults(HospitalProvisioner $provisioner): void
    {
        $this->authorize('roles.manage');
        $provisioner->createDefaultRoles(hospital());
        $this->loadRole();
        $this->toast('Default roles restored.');
    }

    public function with(): array
    {
        $h = hospital();
        $roles = Role::where('hospital_id', $h->id)->withCount('users')->orderBy('name')->get();

        return [
            'roles' => $roles,
            'current' => $roles->firstWhere('id', $this->roleId),
            'modules' => collect(config('hms.modules'))->only($h->modules()),
            'locked' => collect(config('hms.modules'))->except($h->modules()),
        ];
    }
}; ?>

<div>
    <x-page-header title="Roles & Permissions" subtitle="Access control">
        <button class="btn btn-light btn-sm" x-on:click="$confirm('Re-create any missing default roles and reset their permissions to defaults?', () => $wire.restoreDefaults(), { color: 'warning' })">Restore defaults</button>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-3">
            <div class="card">
                <div class="list-group list-group-flush">
                    @foreach ($roles as $r)
                        <button class="list-group-item list-group-item-action d-flex justify-content-between {{ $r->id === $roleId ? 'active' : '' }}" wire:click="$set('roleId', {{ $r->id }})">
                            <span>{{ $r->name }} @if (in_array($r->name, config('hms.protected_roles')))<i class="ri-lock-line fs-12"></i>@endif</span>
                            <span class="badge bg-light text-body">{{ $r->users_count }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">New custom role</h6></div>
                <div class="card-body">
                    <x-form.input model="newRole" placeholder="e.g. Senior Pharmacist" />
                    <select class="form-select mb-3" wire:model="copyFrom"><option value="">Start empty</option>@foreach ($roles as $r)<option value="{{ $r->id }}">Copy from {{ $r->name }}</option>@endforeach</select>
                    <button class="btn btn-primary w-100" wire:click="create">Create role</button>
                </div>
            </div>
        </div>
        <div class="col-xl-9">
            @if ($current)
                <div class="card mb-0">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                        @if (in_array($current->name, config('hms.protected_roles')))
                            <h5 class="card-title mb-0 me-auto">{{ $current->name }}</h5>
                        @else
                            <input type="text" class="form-control w-auto me-auto" wire:model="rename">
                            <button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Delete role {{ $current->name }}?', () => $wire.delete())">Delete</button>
                        @endif
                        <button class="btn btn-sm btn-primary" wire:click="save" @disabled($current->name === 'Hospital Admin')>Save permissions</button>
                    </div>
                    <div class="card-body">
                        @if ($current->name === 'Hospital Admin')
                            <div class="alert alert-info py-2">Hospital Admin automatically has every permission included in your subscription plan.</div>
                        @endif
                        <div class="row g-3">
                            @foreach ($modules as $key => $module)
                                @php $perms = array_keys($module['permissions']); $on = count(array_intersect($perms, $selected)); @endphp
                                <div class="col-md-6" wire:key="mod-{{ $key }}">
                                    <div class="border rounded p-3 h-100">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="mb-0">{{ $module['label'] }}</h6>
                                            <button class="btn btn-sm btn-link p-0" wire:click="toggleModule('{{ $key }}')" @disabled($current->name === 'Hospital Admin')>{{ $on === count($perms) ? 'Clear' : 'All' }} <span class="text-muted">({{ $on }}/{{ count($perms) }})</span></button>
                                        </div>
                                        @foreach ($module['permissions'] as $perm => $label)
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="p-{{ $perm }}" value="{{ $perm }}" wire:model="selected" @disabled($current->name === 'Hospital Admin')>
                                                <label class="form-check-label fs-13" for="p-{{ $perm }}">{{ $label }} <code class="fs-11 text-muted">{{ $perm }}</code></label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if ($locked->isNotEmpty())
                            <p class="fs-12 text-muted mt-3 mb-0"><i class="ri-lock-line"></i> Not in your plan: {{ $locked->pluck('label')->join(', ') }}. @can('subscription.manage')<a href="{{ route('tenant.subscription') }}" wire:navigate>Upgrade</a>@endcan</p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
