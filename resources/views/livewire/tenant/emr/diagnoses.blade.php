<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Diagnosis;
use App\Models\IcdCode;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Renderless;
use Livewire\Volt\Component;

/** Diagnoses with ICD-10 lookup. */
new class extends Component
{
    use Toasts;

    public Patient $patient;

    public ?Model $visitable = null;

    public ?string $icd = null;

    public string $description = '';

    public string $type = 'provisional';

    #[Renderless]
    public function searchIcd(string $q = ''): array
    {
        return IcdCode::search($q)->orderBy('code')->limit(25)->get()
            ->map(fn ($c) => ['value' => $c->code, 'label' => $c->code.' – '.$c->description])->all();
    }

    public function updatedIcd($value): void
    {
        if ($value && ! $this->description) {
            $this->description = (string) IcdCode::where('code', $value)->value('description');
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()->canAny(['emr.manage', 'opd.consult']), 403);
        $this->validate([
            'icd' => 'nullable|exists:icd_codes,code',
            'description' => 'required_without:icd|nullable|string|max:255',
            'type' => 'required|in:provisional,final',
        ]);

        Diagnosis::create([
            'patient_id' => $this->patient->id,
            'visitable_type' => $this->visitable?->getMorphClass(),
            'visitable_id' => $this->visitable?->getKey(),
            'icd_code' => $this->icd,
            'description' => $this->description ?: IcdCode::where('code', $this->icd)->value('description'),
            'type' => $this->type,
            'diagnosed_by' => auth()->id(),
        ]);

        $this->reset('icd', 'description');
        $this->toast('Diagnosis added.');
        $this->dispatch('emr-updated');
    }

    public function delete(int $id): void
    {
        abort_unless(auth()->user()->canAny(['emr.manage', 'opd.consult']), 403);
        $this->patient->diagnoses()->findOrFail($id)->delete();
        $this->dispatch('emr-updated');
    }

    public function with(): array
    {
        return [
            'diagnoses' => $this->patient->diagnoses()->with('diagnosedBy')
                ->when($this->visitable, fn ($q) => $q->where('visitable_type', $this->visitable->getMorphClass())->where('visitable_id', $this->visitable->getKey()))
                ->latest()->get(),
            'canAdd' => auth()->user()->canAny(['emr.manage', 'opd.consult']),
        ];
    }
}; ?>

<div>
    @if ($canAdd)
        <div class="row g-2 align-items-end mb-3">
            <x-form.search-select class="col-md-5 mb-0" label="ICD-10 code" model="icd" search="searchIcd" placeholder="Search ICD-10..." live :selected-label="$icd" />
            <x-form.input class="col-md-4 mb-0" label="Description" model="description" />
            <div class="col-md-2">
                <select class="form-select" wire:model="type"><option value="provisional">Provisional</option><option value="final">Final</option></select>
            </div>
            <div class="col-md-1"><button class="btn btn-primary w-100" wire:click="save" title="Add"><i class="ri-add-line"></i></button></div>
        </div>
    @endif
    <ul class="list-group">
        @forelse ($diagnoses as $d)
            <li class="list-group-item d-flex justify-content-between align-items-center" wire:key="dx-{{ $d->id }}">
                <div>
                    @if ($d->icd_code)<span class="badge bg-primary-subtle text-primary me-1">{{ $d->icd_code }}</span>@endif
                    <strong>{{ $d->description }}</strong>
                    <div class="fs-12 text-muted">{{ label($d->type) }} · {{ $d->diagnosedBy?->name }} · {{ fmt_date($d->created_at) }}</div>
                </div>
                @if ($canAdd)
                    <button class="btn btn-sm btn-light-danger icon-btn-sm" x-on:click="$confirm('Remove this diagnosis?', () => $wire.delete({{ $d->id }}))"><i class="ri-delete-bin-line"></i></button>
                @endif
            </li>
        @empty
            <li class="list-group-item text-muted text-center py-4">No diagnoses recorded.</li>
        @endforelse
    </ul>
</div>
