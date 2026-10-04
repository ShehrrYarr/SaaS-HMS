<?php

use App\Livewire\Concerns\Toasts;
use App\Models\OtRoom;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('OT Rooms')] class extends Component
{
    use Toasts;

    public string $name = '';

    public string $notes = '';

    public function add(): void
    {
        $this->authorize('ot.manage');
        $this->validate(['name' => 'required|string|max:100', 'notes' => 'nullable|string|max:255']);
        OtRoom::create(['name' => $this->name, 'notes' => $this->notes ?: null]);
        $this->reset('name', 'notes');
        $this->toast('Room added.');
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorize('ot.manage');
        abort_unless(in_array($status, ['available', 'in_use', 'cleaning', 'maintenance']), 400);
        OtRoom::findOrFail($id)->update(['status' => $status]);
    }

    public function with(): array
    {
        return ['rooms' => OtRoom::withCount(['surgeries as upcoming' => fn ($q) => $q->where('scheduled_start', '>=', now())->whereNotIn('status', ['cancelled', 'completed'])])->orderBy('name')->get()];
    }
}; ?>

<div>
    <x-page-header title="OT Rooms" subtitle="Theaters" :breadcrumbs="['OT Schedule' => route('tenant.ot.index')]" />
    <div class="card mb-4">
        <div class="card-body row g-2 align-items-end">
            <x-form.input class="col-md-4 mb-0" label="Room name" model="name" />
            <x-form.input class="col-md-6 mb-0" label="Notes / equipment" model="notes" />
            <div class="col-md-2"><button class="btn btn-primary w-100" wire:click="add">Add room</button></div>
        </div>
    </div>
    <div class="row g-4">
        @foreach ($rooms as $r)
            <div class="col-md-4">
                <div class="card mb-0 h-100">
                    <div class="card-body">
                        <h5>{{ $r->name }}</h5>
                        <p class="text-muted fs-13">{{ $r->notes ?: 'No notes' }} · {{ $r->upcoming }} upcoming</p>
                        <div class="btn-group btn-group-sm">
                            @foreach (['available', 'in_use', 'cleaning', 'maintenance'] as $s)
                                <button class="btn {{ $r->status === $s ? 'btn-'.status_color($s) : 'btn-light' }}" wire:click="setStatus({{ $r->id }}, '{{ $s }}')">{{ label($s) }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
