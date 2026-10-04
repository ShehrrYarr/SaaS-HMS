<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Hospital;
use App\Models\Plan;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Hospitals')] class extends Component
{
    use WithTable;

    #[Url]
    public string $status = '';

    #[Url]
    public string $plan = '';

    protected array $sortable = ['name', 'created_at', 'subscription_ends_at', 'status'];

    protected string $defaultSort = 'created_at';

    public function toggleStatus(int $id, SubscriptionService $billing): void
    {
        $hospital = Hospital::findOrFail($id);
        if ($hospital->isSuspended()) {
            $billing->activate($hospital);
            $this->toast("{$hospital->name} activated.");
        } else {
            $billing->suspend($hospital, 'Suspended by platform administrator.');
            $this->toast("{$hospital->name} suspended.", 'warning');
        }
    }

    public function with(): array
    {
        $query = Hospital::with('plan')
            ->withCount(['users'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('slug', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->plan, fn ($q) => $q->where('plan_id', $this->plan));

        return [
            'hospitals' => $this->applySort($query)->paginate($this->perPage),
            'plans' => Plan::orderBy('sort_order')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Hospitals" subtitle="Tenants" :breadcrumbs="['Platform' => route('admin.dashboard')]">
        <a href="{{ route('admin.hospitals.create') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-add-line me-1"></i>New Hospital</a>
    </x-page-header>

    <div class="card">
        <x-table-toolbar placeholder="Search name, slug, code, email...">
            <select class="form-select w-auto" wire:model.live="status">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="trial">Trial</option>
                <option value="suspended">Suspended</option>
            </select>
            <select class="form-select w-auto" wire:model.live="plan">
                <option value="">All plans</option>
                @foreach ($plans as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <x-th field="name" :sort="$sortField" :dir="$sortDirection">Hospital</x-th>
                        <th>Plan</th>
                        <th>Users</th>
                        <x-th field="subscription_ends_at" :sort="$sortField" :dir="$sortDirection">Paid until</x-th>
                        <x-th field="status" :sort="$sortField" :dir="$sortDirection">Status</x-th>
                        <x-th field="created_at" :sort="$sortField" :dir="$sortDirection">Joined</x-th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($hospitals as $h)
                        <tr wire:key="h-{{ $h->id }}">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :name="$h->name" />
                                    <div>
                                        <a href="{{ route('admin.hospitals.show', $h) }}" wire:navigate class="fw-semibold">{{ $h->name }}</a>
                                        <div class="fs-12 text-muted">{{ $h->code }} &middot; <a href="{{ route('tenant.login', ['hospital' => $h->slug]) }}" target="_blank">/h/{{ $h->slug }}</a></div>
                                    </div>
                                </div>
                            </td>
                            <td>{{ $h->plan?->name ?? '—' }} <span class="text-muted fs-12">({{ $h->billing_cycle }})</span></td>
                            <td>{{ $h->users_count }}</td>
                            <td class="{{ $h->subscription_ends_at?->isPast() ? 'text-danger' : '' }}">{{ fmt_date($h->subscription_ends_at) }}</td>
                            <td><x-status :value="$h->status" /></td>
                            <td>{{ fmt_date($h->created_at) }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.hospitals.show', $h) }}" wire:navigate class="btn btn-sm btn-light-primary icon-btn-sm" title="Manage"><i class="ri-settings-3-line"></i></a>
                                <form method="POST" action="{{ route('admin.impersonate', $h) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-light-info icon-btn-sm" title="Login as hospital admin"><i class="ri-login-box-line"></i></button>
                                </form>
                                <button type="button" class="btn btn-sm {{ $h->isSuspended() ? 'btn-light-success' : 'btn-light-danger' }} icon-btn-sm"
                                    title="{{ $h->isSuspended() ? 'Activate' : 'Suspend' }}"
                                    x-on:click="$confirm('{{ $h->isSuspended() ? 'Re-activate' : 'Suspend' }} {{ addslashes($h->name) }}?', () => $wire.toggleStatus({{ $h->id }}), { color: '{{ $h->isSuspended() ? 'success' : 'danger' }}' })">
                                    <i class="{{ $h->isSuspended() ? 'ri-play-circle-line' : 'ri-pause-circle-line' }}"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="7" message="No hospitals found." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $hospitals->links() }}</div>
    </div>
</div>
