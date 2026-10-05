<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Hospital;
use App\Models\Plan;
use App\Services\HospitalProvisioner;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Subscription Plans')] class extends Component
{
    use Toasts;

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function create(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'description' => '', 'price_monthly' => 0, 'price_yearly' => 0, 'trial_days' => 14, 'is_active' => true, 'sort_order' => Plan::max('sort_order') + 1, 'modules' => []];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $plan = Plan::findOrFail($id);
        $this->editingId = $id;
        $this->form = $plan->only(['name', 'description', 'price_monthly', 'price_yearly', 'trial_days', 'is_active', 'sort_order']) + ['modules' => $plan->modules ?? []];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(HospitalProvisioner $provisioner): void
    {
        $data = $this->validate([
            'form.name' => 'required|string|max:80',
            'form.description' => 'nullable|string|max:255',
            'form.price_monthly' => 'required|integer|min:0',
            'form.price_yearly' => 'required|integer|min:0',
            'form.trial_days' => 'required|integer|min:0|max:365',
            'form.is_active' => 'boolean',
            'form.sort_order' => 'integer',
            'form.modules' => 'array',
            'form.modules.*' => Rule::in(array_keys(Plan::sellableModules())),
        ])['form'];

        if ($this->editingId) {
            $plan = Plan::findOrFail($this->editingId);
            $plan->update($data);
            // Keep every subscribed hospital's role permissions in line with the plan.
            Hospital::where('plan_id', $plan->id)->each(function (Hospital $h) use ($provisioner) {
                $provisioner->syncPlanPermissions($h);
                $provisioner->createDefaultRoles($h);
            });
        } else {
            Plan::create($data + ['slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(4))]);
        }

        $this->showForm = false;
        $this->toast('Plan saved.');
    }

    public function delete(int $id): void
    {
        $plan = Plan::withCount('hospitals')->findOrFail($id);
        if ($plan->hospitals_count > 0) {
            $this->toast('Plan is in use by hospitals; deactivate it instead.', 'error');

            return;
        }
        $plan->delete();
        $this->toast('Plan deleted.');
    }

    public function with(): array
    {
        return [
            'plans' => Plan::withCount('hospitals')->orderBy('sort_order')->get(),
            'modules' => Plan::sellableModules(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Subscription Plans" subtitle="Plans" :breadcrumbs="['Platform' => route('admin.dashboard')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New plan</button>
    </x-page-header>

    <div class="row g-4">
        @foreach ($plans as $plan)
            <div class="col-md-6 col-xl-4" wire:key="plan-{{ $plan->id }}">
                <div class="card h-100 mb-0 {{ $plan->is_active ? '' : 'opacity-75' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="mb-0">{{ $plan->name }}</h5>
                            @unless ($plan->is_active)<span class="badge bg-secondary">Inactive</span>@endunless
                        </div>
                        <p class="text-muted">{{ $plan->description }}</p>
                        <h3 class="fw-bold mb-0">{{ money($plan->price_monthly) }}<small class="fs-13 text-muted fw-normal"> / month</small></h3>
                        <p class="text-muted fs-12">{{ money($plan->price_yearly) }} / year &middot; {{ $plan->trial_days }}-day trial</p>
                        <ul class="list-unstyled mb-3">
                            @foreach ($modules as $key => $label)
                                <li class="{{ in_array($key, $plan->modules ?? []) ? '' : 'text-muted text-decoration-line-through' }}">
                                    <i class="{{ in_array($key, $plan->modules ?? []) ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-line' }} me-1"></i>{{ $label }}
                                </li>
                            @endforeach
                        </ul>
                        <p class="fs-12 text-muted mb-0">Core included: Patients &amp; EMR, Administration</p>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center">
                        <span class="text-muted fs-12">{{ $plan->hospitals_count }} hospitals</span>
                        <div>
                            <button class="btn btn-sm btn-light-primary" wire:click="edit({{ $plan->id }})">Edit</button>
                            <button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Delete plan {{ $plan->name }}?', () => $wire.delete({{ $plan->id }}))">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit plan' : 'New plan'" size="lg">
        <div class="row">
            <x-form.input class="col-md-6" label="Name" model="form.name" required />
            <x-form.input class="col-md-3" label="Trial days" model="form.trial_days" type="number" />
            <x-form.input class="col-md-3" label="Sort" model="form.sort_order" type="number" />
            <x-form.input class="col-12" label="Description" model="form.description" />
            <x-form.money class="col-md-6" label="Monthly price" model="form.price_monthly" required />
            <x-form.money class="col-md-6" label="Yearly price" model="form.price_yearly" required />
            <div class="col-12">
                <label class="form-label">Modules included</label>
                <div class="row">
                    @foreach ($modules as $key => $label)
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="m-{{ $key }}" value="{{ $key }}" wire:model="form.modules">
                                <label class="form-check-label" for="m-{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <x-form.switch class="col-12 mt-2" label="Active (available for new hospitals)" model="form.is_active" />
        </div>
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-primary" wire:click="save">Save plan</button>
        </x-slot:footer>
    </x-modal>
</div>
