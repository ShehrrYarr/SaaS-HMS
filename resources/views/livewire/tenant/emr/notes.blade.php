<?php

use App\Livewire\Concerns\Toasts;
use App\Models\ClinicalNote;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Livewire\Volt\Component;

/** SOAP clinical / progress / nursing notes. */
new class extends Component
{
    use Toasts;

    public Patient $patient;

    public ?Model $visitable = null;

    public string $defaultType = 'consultation';

    public array $form = [];

    public function mount(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->form = ['type' => $this->defaultType, 'subjective' => '', 'objective' => '', 'assessment' => '', 'plan' => '', 'note' => ''];
    }

    public function save(): void
    {
        abort_unless(auth()->user()->canAny(['emr.manage', 'ipd.vitals', 'opd.consult']), 403);
        $data = $this->validate([
            'form.type' => 'required|in:consultation,progress,nursing,procedure,discharge',
            'form.subjective' => 'nullable|string|max:5000',
            'form.objective' => 'nullable|string|max:5000',
            'form.assessment' => 'nullable|string|max:5000',
            'form.plan' => 'nullable|string|max:5000',
            'form.note' => 'nullable|string|max:5000',
        ])['form'];

        if (! array_filter(array_diff_key($data, ['type' => 1]))) {
            $this->addError('form.note', 'Write something before saving.');

            return;
        }

        ClinicalNote::create(array_map(fn ($v) => $v === '' ? null : $v, $data) + [
            'patient_id' => $this->patient->id,
            'visitable_type' => $this->visitable?->getMorphClass(),
            'visitable_id' => $this->visitable?->getKey(),
            'author_id' => auth()->id(),
        ]);

        $this->resetForm();
        $this->toast('Note saved.');
    }

    public function with(): array
    {
        return [
            'notes' => $this->patient->clinicalNotes()->with('author')
                ->when($this->visitable, fn ($q) => $q->where('visitable_type', $this->visitable->getMorphClass())->where('visitable_id', $this->visitable->getKey()))
                ->latest()->limit(50)->get(),
            'canAdd' => auth()->user()->canAny(['emr.manage', 'ipd.vitals', 'opd.consult']),
        ];
    }
}; ?>

<div>
    @if ($canAdd)
        <form wire:submit="save" class="border rounded p-3 mb-4 bg-body-tertiary" x-data="{ soap: true }">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <select class="form-select form-select-sm w-auto" wire:model="form.type">
                    @foreach (['consultation', 'progress', 'nursing', 'procedure', 'discharge'] as $t)<option value="{{ $t }}">{{ label($t) }} note</option>@endforeach
                </select>
                <div class="form-check form-switch ms-auto mb-0">
                    <input class="form-check-input" type="checkbox" id="soap-{{ $this->getId() }}" x-model="soap">
                    <label class="form-check-label fs-13" for="soap-{{ $this->getId() }}">SOAP format</label>
                </div>
            </div>
            <div x-show="soap" class="row g-2">
                <div class="col-md-6"><textarea class="form-control form-control-sm" rows="2" placeholder="S – Subjective (history, complaints)" wire:model="form.subjective"></textarea></div>
                <div class="col-md-6"><textarea class="form-control form-control-sm" rows="2" placeholder="O – Objective (examination findings)" wire:model="form.objective"></textarea></div>
                <div class="col-md-6"><textarea class="form-control form-control-sm" rows="2" placeholder="A – Assessment" wire:model="form.assessment"></textarea></div>
                <div class="col-md-6"><textarea class="form-control form-control-sm" rows="2" placeholder="P – Plan" wire:model="form.plan"></textarea></div>
            </div>
            <div x-show="!soap"><textarea class="form-control form-control-sm" rows="4" placeholder="Free-text note" wire:model="form.note"></textarea></div>
            @error('form.note')<div class="text-danger fs-12 mt-1">{{ $message }}</div>@enderror
            <div class="text-end mt-2"><button class="btn btn-sm btn-primary"><i class="ri-save-line me-1"></i>Save note</button></div>
        </form>
    @endif

    @forelse ($notes as $n)
        <div class="border-start border-3 border-primary ps-3 mb-3" wire:key="note-{{ $n->id }}">
            <div class="d-flex justify-content-between">
                <span><x-status :value="$n->type" /> <strong class="fs-13 ms-1">{{ $n->author?->name }}</strong></span>
                <small class="text-muted">{{ fmt_datetime($n->created_at) }}</small>
            </div>
            <div class="fs-13 mt-1">
                @foreach (['subjective' => 'S', 'objective' => 'O', 'assessment' => 'A', 'plan' => 'P'] as $f => $l)
                    @if ($n->{$f})<div><strong>{{ $l }}:</strong> {!! nl2br(e($n->{$f})) !!}</div>@endif
                @endforeach
                @if ($n->note)<div>{!! nl2br(e($n->note)) !!}</div>@endif
            </div>
        </div>
    @empty
        <p class="text-muted text-center py-4 mb-0">No notes yet.</p>
    @endforelse
</div>
