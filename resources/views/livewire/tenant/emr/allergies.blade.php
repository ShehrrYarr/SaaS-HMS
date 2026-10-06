<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Patient;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public Patient $patient;

    public array $form = ['allergen' => '', 'type' => 'drug', 'reaction' => '', 'severity' => 'moderate'];

    public function save(): void
    {
        $this->authorize('emr.manage');
        $data = $this->validate([
            'form.allergen' => 'required|string|max:120',
            'form.type' => 'required|in:drug,food,environmental,other',
            'form.reaction' => 'nullable|string|max:200',
            'form.severity' => 'required|in:mild,moderate,severe',
        ])['form'];
        $this->patient->allergies()->create($data + ['noted_at' => today(), 'recorded_by' => auth()->id()]);
        $this->form = ['allergen' => '', 'type' => 'drug', 'reaction' => '', 'severity' => 'moderate'];
        $this->toast('Allergy recorded.');
        $this->dispatch('emr-updated');
    }

    public function delete(int $id): void
    {
        $this->authorize('emr.manage');
        $this->patient->allergies()->findOrFail($id)->delete();
        $this->dispatch('emr-updated');
    }

    public function with(): array
    {
        return ['allergies' => $this->patient->allergies()->with('recorder')->latest()->get()];
    }
}; ?>

<div>
    @can('emr.manage')
        <div class="row g-2 align-items-end mb-3">
            <x-form.input class="col-md-4 mb-0" label="Allergen" model="form.allergen" placeholder="e.g. Penicillin, Peanuts" />
            <x-form.select class="col-md-2 mb-0" label="Type" model="form.type" :options="['drug' => 'Drug', 'food' => 'Food', 'environmental' => 'Environmental', 'other' => 'Other']" :placeholder="false" />
            <x-form.input class="col-md-3 mb-0" label="Reaction" model="form.reaction" />
            <x-form.select class="col-md-2 mb-0" label="Severity" model="form.severity" :options="['mild' => 'Mild', 'moderate' => 'Moderate', 'severe' => 'Severe']" :placeholder="false" />
            <div class="col-md-1"><button title="Add" aria-label="Add" class="btn btn-primary w-100" wire:click="save"><i class="ri-add-line"></i></button></div>
        </div>
    @endcan
    <table class="table table-hms table-sm mb-0">
        <thead><tr><th>Allergen</th><th>Type</th><th>Reaction</th><th>Severity</th><th>Recorded</th><th></th></tr></thead>
        <tbody>
            @forelse ($allergies as $a)
                <tr wire:key="al-{{ $a->id }}">
                    <td class="fw-semibold">{{ $a->allergen }}</td><td>{{ label($a->type) }}</td><td>{{ $a->reaction ?: '—' }}</td>
                    <td><span class="badge bg-{{ $a->severity === 'severe' ? 'danger' : ($a->severity === 'moderate' ? 'warning' : 'info') }}-subtle text-{{ $a->severity === 'severe' ? 'danger' : ($a->severity === 'moderate' ? 'warning' : 'info') }}">{{ label($a->severity) }}</span></td>
                    <td class="fs-12 text-muted">{{ fmt_date($a->created_at) }} · {{ $a->recorder?->name }}</td>
                    <td class="text-end">@can('emr.manage')<button title="Remove" aria-label="Remove" class="btn btn-sm btn-light-danger icon-btn-sm" x-on:click="$confirm('Remove allergy?', () => $wire.delete({{ $a->id }}))"><i class="ri-delete-bin-line"></i></button>@endcan</td>
                </tr>
            @empty
                <x-empty-row :colspan="6" message="No known allergies." />
            @endforelse
        </tbody>
    </table>
</div>
