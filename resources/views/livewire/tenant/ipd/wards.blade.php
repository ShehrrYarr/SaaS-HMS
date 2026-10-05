<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Bed;
use App\Models\Ward;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Wards & Beds')] class extends Component
{
    use Toasts;

    public bool $showWard = false;

    public ?int $editingId = null;

    public array $ward = [];

    public bool $showBeds = false;

    public ?int $bedWardId = null;

    public string $prefix = '';

    public int $count = 1;

    public string $bedCharge = '';

    public function create(): void
    {
        $this->editingId = null;
        $this->ward = ['name' => '', 'type' => 'general', 'floor' => '', 'charge_per_day' => '', 'description' => '', 'is_active' => true];
        $this->resetValidation();
        $this->showWard = true;
    }

    public function edit(int $id): void
    {
        $w = Ward::findOrFail($id);
        $this->editingId = $id;
        $this->ward = $w->only(['name', 'type', 'floor', 'charge_per_day', 'description', 'is_active']);
        $this->resetValidation();
        $this->showWard = true;
    }

    public function saveWard(): void
    {
        $this->authorize('beds.manage');
        $data = $this->validate([
            'ward.name' => ['required', 'string', 'max:100', tenant_unique('wards', 'name', $this->editingId)],
            'ward.type' => 'required|in:'.implode(',', array_keys(Ward::TYPES)),
            'ward.floor' => 'nullable|string|max:30',
            'ward.charge_per_day' => 'required|integer|min:0',
            'ward.description' => 'nullable|string|max:500',
            'ward.is_active' => 'boolean',
        ])['ward'];
        $this->editingId ? Ward::findOrFail($this->editingId)->update($data) : Ward::create($data);
        $this->showWard = false;
        $this->toast('Ward saved.');
    }

    public function addBeds(int $wardId): void
    {
        $w = Ward::findOrFail($wardId);
        $this->bedWardId = $wardId;
        $this->prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $w->name), 0, 3)).'-';
        $this->count = 1;
        $this->bedCharge = '';
        $this->showBeds = true;
    }

    public function saveBeds(): void
    {
        $this->authorize('beds.manage');
        $this->validate(['prefix' => 'required|string|max:10', 'count' => 'required|integer|min:1|max:100', 'bedCharge' => 'nullable|integer|min:0']);
        $ward = Ward::findOrFail($this->bedWardId);
        $existing = $ward->beds()->pluck('bed_no')->all();
        $n = 1;
        $created = 0;
        while ($created < $this->count) {
            $no = $this->prefix.str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
            if (in_array($no, $existing)) {
                continue;
            }
            Bed::create(['ward_id' => $ward->id, 'bed_no' => $no, 'charge_per_day' => $this->bedCharge === '' ? null : $this->bedCharge]);
            $created++;
        }
        $this->showBeds = false;
        $this->toast("{$created} bed(s) added to {$ward->name}.");
    }

    public function deleteBed(int $id): void
    {
        $this->authorize('beds.manage');
        $bed = Bed::findOrFail($id);
        if ($bed->status === 'occupied' || $bed->allocations()->exists()) {
            $this->toast('Beds with admission history cannot be deleted; mark them under maintenance instead.', 'error');

            return;
        }
        $bed->delete();
    }

    public function with(): array
    {
        return ['wards' => Ward::withCount('beds')->with(['beds' => fn ($q) => $q->orderBy('bed_no')])->orderBy('name')->get(), 'types' => Ward::TYPES];
    }
}; ?>

<div>
    <x-page-header title="Wards & Beds" subtitle="Configuration" :breadcrumbs="['IPD' => route('tenant.ipd.index')]">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>New ward</button>
    </x-page-header>

    <div class="row g-4">
        @foreach ($wards as $w)
            <div class="col-md-6" wire:key="w-{{ $w->id }}">
                <div class="card h-100 mb-0 {{ $w->is_active ? '' : 'opacity-50' }}">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div><h5 class="card-title mb-0">{{ $w->name }}</h5><small class="text-muted">{{ $types[$w->type] ?? $w->type }} · Floor {{ $w->floor ?: '—' }} · {{ money($w->charge_per_day) }}/day</small></div>
                        <div>
                            <button class="btn btn-sm btn-light-primary" wire:click="edit({{ $w->id }})">Edit</button>
                            <button class="btn btn-sm btn-light-success" wire:click="addBeds({{ $w->id }})">+ Beds</button>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        @forelse ($w->beds as $b)
                            <span class="badge bg-{{ status_color($b->status) }}-subtle text-{{ status_color($b->status) }} p-2">
                                {{ $b->bed_no }} @if ($b->charge_per_day)<small>({{ money($b->charge_per_day) }})</small>@endif
                                @if ($b->status !== 'occupied')<i class="ri-close-line ms-1" role="button" x-on:click="$confirm('Delete bed {{ $b->bed_no }}?', () => $wire.deleteBed({{ $b->id }}))"></i>@endif
                            </span>
                        @empty
                            <span class="text-muted">No beds yet.</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <x-modal wire:model="showWard" :title="$editingId ? 'Edit ward' : 'New ward'">
        <x-form.input label="Name" model="ward.name" required />
        <div class="row">
            <x-form.select class="col-md-6" label="Type" model="ward.type" :options="$types" :placeholder="false" />
            <x-form.input class="col-md-3" label="Floor" model="ward.floor" />
            <x-form.money class="col-md-3" label="Charge/day" model="ward.charge_per_day" />
        </div>
        <x-form.textarea label="Description" model="ward.description" rows="2" />
        <x-form.switch label="Active" model="ward.is_active" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveWard">Save</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showBeds" title="Add beds">
        <div class="row">
            <x-form.input class="col-md-4" label="Prefix" model="prefix" />
            <x-form.input class="col-md-4" label="How many" model="count" type="number" min="1" />
            <x-form.money class="col-md-4" label="Charge override" model="bedCharge" placeholder="Ward rate" />
        </div>
        <p class="text-muted fs-12 mb-0">Beds are numbered {{ $prefix }}01, {{ $prefix }}02 … skipping existing numbers.</p>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveBeds">Add beds</button></x-slot:footer>
    </x-modal>
</div>
