<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use Toasts, WithFileUploads;

    public Patient $patient;

    public $file;

    public string $title = '';

    public string $category = 'other';

    public const CATEGORIES = ['lab_report' => 'Lab report', 'imaging' => 'Imaging', 'prescription' => 'Prescription', 'discharge' => 'Discharge summary', 'referral' => 'Referral letter', 'insurance' => 'Insurance', 'id_proof' => 'ID proof', 'consent' => 'Consent form', 'other' => 'Other'];

    public function save(): void
    {
        $this->authorize('emr.documents');
        $this->validate([
            'file' => 'required|file|max:20480|mimes:pdf,jpg,jpeg,png,webp,doc,docx,dcm,txt',
            'title' => 'required|string|max:150',
            'category' => 'required|in:'.implode(',', array_keys(self::CATEGORIES)),
        ]);

        $path = $this->file->store(hospital()->storagePath("patients/{$this->patient->id}/documents"), 'local');
        $size = $this->file->getSize();

        $this->patient->documents()->create([
            'title' => $this->title,
            'category' => $this->category,
            'file_path' => $path,
            'file_name' => $this->file->getClientOriginalName(),
            'mime' => $this->file->getMimeType(),
            'size' => $size,
            'uploaded_by' => auth()->id(),
        ]);
        Hospital::whereKey(hospital()->id)->increment('storage_used_bytes', $size);

        $this->reset('file', 'title');
        $this->toast('Document uploaded.');
    }

    public function delete(int $id): void
    {
        $this->authorize('emr.documents');
        $doc = $this->patient->documents()->findOrFail($id);
        Storage::disk('local')->delete($doc->file_path);
        Hospital::whereKey(hospital()->id)->where('storage_used_bytes', '>=', $doc->size)->decrement('storage_used_bytes', $doc->size);
        $doc->delete();
        $this->toast('Document deleted.', 'warning');
    }

    public function with(): array
    {
        return ['documents' => $this->patient->documents()->with('uploader')->latest()->get(), 'categories' => self::CATEGORIES];
    }
}; ?>

<div>
    @can('emr.documents')
        <form wire:submit="save" class="row g-2 align-items-end mb-4">
            <x-form.input class="col-md-4 mb-0" label="Title" model="title" placeholder="e.g. Outside MRI report" />
            <x-form.select class="col-md-3 mb-0" label="Category" model="category" :options="$categories" :placeholder="false" />
            <div class="col-md-4">
                <label class="form-label">File <small class="text-muted">(PDF, image, DICOM, max 20 MB)</small></label>
                <input type="file" class="form-control @error('file') is-invalid @enderror" wire:model="file">
                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div wire:loading wire:target="file" class="fs-12 text-primary">Uploading…</div>
            </div>
            <div class="col-md-1"><button class="btn btn-primary w-100" wire:loading.attr="disabled"><i class="ri-upload-2-line"></i></button></div>
        </form>
    @endcan
    <div class="row g-3">
        @forelse ($documents as $d)
            <div class="col-md-6 col-xl-4" wire:key="doc-{{ $d->id }}">
                <div class="border rounded p-3 h-100 d-flex gap-3">
                    <div class="avatar-item avatar avatar-title bg-primary-subtle text-primary flex-shrink-0">
                        <i class="{{ str_contains((string) $d->mime, 'pdf') ? 'ri-file-pdf-2-line' : (str_contains((string) $d->mime, 'image') ? 'ri-image-line' : 'ri-file-line') }}"></i>
                    </div>
                    <div class="min-w-0 flex-grow-1">
                        <a href="{{ route('files.show', ['path' => $d->file_path]) }}" target="_blank" class="fw-semibold d-block text-truncate">{{ $d->title }}</a>
                        <small class="text-muted d-block">{{ $categories[$d->category] ?? label($d->category) }} · {{ human_bytes($d->size) }}</small>
                        <small class="text-muted">{{ fmt_date($d->created_at) }} · {{ $d->uploader?->name }}</small>
                    </div>
                    @can('emr.documents')<button class="btn btn-sm btn-link text-danger p-0 align-self-start" x-on:click="$confirm('Delete {{ addslashes($d->title) }}?', () => $wire.delete({{ $d->id }}))"><i class="ri-delete-bin-line"></i></button>@endcan
                </div>
            </div>
        @empty
            <div class="col-12 text-muted text-center py-4">No documents uploaded.</div>
        @endforelse
    </div>
</div>
