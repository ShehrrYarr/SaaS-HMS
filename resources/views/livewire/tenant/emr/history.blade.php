<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Patient;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public Patient $patient;

    public array $form = ['type' => 'past_illness', 'title' => '', 'details' => '', 'since_date' => ''];

    public const TYPES = ['past_illness' => 'Past illness', 'surgery' => 'Surgical history', 'family' => 'Family history', 'social' => 'Social history', 'medication' => 'Current medication', 'immunization' => 'Immunization'];

    public function save(): void
    {
        $this->authorize('emr.manage');
        $data = $this->validate([
            'form.type' => 'required|in:'.implode(',', array_keys(self::TYPES)),
            'form.title' => 'required|string|max:150',
            'form.details' => 'nullable|string|max:2000',
            'form.since_date' => 'nullable|date',
        ])['form'];
        $this->patient->histories()->create(array_map(fn ($v) => $v === '' ? null : $v, $data) + ['recorded_by' => auth()->id()]);
        $this->form = ['type' => $data['type'], 'title' => '', 'details' => '', 'since_date' => ''];
        $this->toast('History updated.');
    }

    public function delete(int $id): void
    {
        $this->authorize('emr.manage');
        $this->patient->histories()->findOrFail($id)->delete();
    }

    public function with(): array
    {
        return ['groups' => $this->patient->histories()->latest()->get()->groupBy('type'), 'types' => self::TYPES];
    }
}; ?>

<div>
    @can('emr.manage')
        <div class="row g-2 align-items-end mb-4">
            <x-form.select class="col-md-3 mb-0" label="Category" model="form.type" :options="$types" :placeholder="false" />
            <x-form.input class="col-md-3 mb-0" label="Title" model="form.title" placeholder="e.g. Type 2 diabetes" />
            <x-form.input class="col-md-3 mb-0" label="Details" model="form.details" />
            <x-form.input class="col-md-2 mb-0" label="Since" model="form.since_date" type="date" />
            <div class="col-md-1"><button class="btn btn-primary w-100" wire:click="save"><i class="ri-add-line"></i></button></div>
        </div>
    @endcan
    <div class="row g-3">
        @foreach ($types as $key => $label)
            <div class="col-md-6">
                <h6 class="text-muted text-uppercase fs-12">{{ $label }}</h6>
                <ul class="list-group mb-2">
                    @forelse ($groups[$key] ?? [] as $h)
                        <li class="list-group-item d-flex justify-content-between" wire:key="h-{{ $h->id }}">
                            <div><strong>{{ $h->title }}</strong> @if ($h->since_date)<small class="text-muted">since {{ fmt_date($h->since_date, 'M Y') }}</small>@endif
                                @if ($h->details)<div class="fs-13 text-muted">{{ $h->details }}</div>@endif</div>
                            @can('emr.manage')<button class="btn btn-sm btn-link text-danger p-0" x-on:click="$confirm('Remove entry?', () => $wire.delete({{ $h->id }}))"><i class="ri-close-line"></i></button>@endcan
                        </li>
                    @empty
                        <li class="list-group-item text-muted fs-13">None recorded</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
</div>
