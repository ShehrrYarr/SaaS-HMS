<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Hospital;
use App\Models\RadiologyOrder;
use App\Notifications\HmsNotification;
use Illuminate\Support\Facades\Storage;
use App\Services\DiagnosticsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Imaging Study')] class extends Component
{
    use Toasts, WithFileUploads;

    public RadiologyOrder $radiologyOrder;

    public ?string $scheduled_at = null;

    public string $technician_notes = '';

    public string $study_instance_uid = '';

    public array $files = [];

    public string $findings = '';

    public string $impression = '';

    public function mount(RadiologyOrder $radiologyOrder): void
    {
        $this->radiologyOrder = $radiologyOrder;
        $this->scheduled_at = $radiologyOrder->scheduled_at?->format('Y-m-d\TH:i');
        $this->technician_notes = (string) $radiologyOrder->technician_notes;
        $this->study_instance_uid = (string) $radiologyOrder->study_instance_uid;
        $this->findings = (string) $radiologyOrder->findings;
        $this->impression = (string) $radiologyOrder->impression;
    }

    public function schedule(): void
    {
        $this->authorize('radiology.perform');
        $this->validate(['scheduled_at' => 'required|date']);
        $this->radiologyOrder->update(['scheduled_at' => $this->scheduled_at, 'status' => $this->radiologyOrder->status === 'ordered' ? 'scheduled' : $this->radiologyOrder->status]);
        $this->toast('Study scheduled.');
    }

    public function performed(): void
    {
        $this->authorize('radiology.perform');
        $this->validate(['technician_notes' => 'nullable|string|max:2000', 'study_instance_uid' => 'nullable|string|max:128']);
        $this->radiologyOrder->update([
            'status' => in_array($this->radiologyOrder->status, ['ordered', 'scheduled']) ? 'performed' : $this->radiologyOrder->status,
            'performed_at' => $this->radiologyOrder->performed_at ?? now(),
            'performed_by' => $this->radiologyOrder->performed_by ?? auth()->id(),
            'technician_notes' => $this->technician_notes ?: null,
            'study_instance_uid' => $this->study_instance_uid ?: null,
        ]);
        $this->toast('Study marked as performed.');
    }

    // Not "upload": $wire.upload() is Livewire's own file-upload helper, so wire:click="upload" never reached PHP.
    public function uploadFiles(): void
    {
        $this->authorize('radiology.perform');
        $this->validate(['files' => 'required|array|min:1', 'files.*' => 'file|max:102400|extensions:dcm,jpg,jpeg,png,webp,gif,bmp,tif,tiff,pdf'],
            ['files.*.extensions' => 'Upload DICOM (.dcm), image or PDF files only.']);
        $total = 0;
        foreach ($this->files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $type = $ext === 'dcm' || $file->getMimeType() === 'application/dicom' ? 'dicom' : (str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : ($ext === 'pdf' ? 'pdf' : 'other'));
            $path = $file->store(hospital()->storagePath("patients/{$this->radiologyOrder->patient_id}/imaging/{$this->radiologyOrder->id}"), 'local');
            $this->radiologyOrder->attachments()->create([
                'file_path' => $path, 'file_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(),
                'type' => $type, 'size' => $file->getSize(), 'uploaded_by' => auth()->id(),
            ]);
            $total += $file->getSize();
        }
        Hospital::whereKey(hospital()->id)->increment('storage_used_bytes', $total);
        $this->files = [];
        $this->toast('Images uploaded.');
    }

    public function deleteAttachment(int $id): void
    {
        $this->authorize('radiology.perform');
        $a = $this->radiologyOrder->attachments()->findOrFail($id);
        Storage::disk('local')->delete($a->file_path);
        Hospital::whereKey(hospital()->id)->where('storage_used_bytes', '>=', $a->size)->decrement('storage_used_bytes', $a->size);
        $a->delete();
    }

    public function saveReport(bool $final = false): void
    {
        $this->authorize('radiology.report');
        if (in_array($this->radiologyOrder->status, ['approved', 'cancelled'], true)) {
            $this->toast('This report is already finalised and released, so it can no longer be changed.', 'error');

            return;
        }
        $this->validate(['findings' => 'required|string|max:10000', 'impression' => 'required|string|max:3000']);
        $this->radiologyOrder->update([
            'findings' => $this->findings,
            'impression' => $this->impression,
            'radiologist_id' => auth()->id(),
            'reported_at' => $this->radiologyOrder->reported_at ?? now(),
            'status' => $final ? 'approved' : 'reported',
            'approved_at' => $final ? now() : null,
            'performed_at' => $this->radiologyOrder->performed_at ?? now(),
        ]);
        if ($final) {
            $o = $this->radiologyOrder->fresh(['patient.user', 'doctor.user', 'test']);
            $o->patient->user?->notify(new HmsNotification('Imaging report ready', "{$o->test->name} report is available.", route('portal.lab-reports'), 'ri-scan-2-line', 'success'));
            $o->doctor?->user?->notify(new HmsNotification('Imaging report finalised', "{$o->patient->full_name} – {$o->test->name}", route('tenant.radiology.order', $o), 'ri-scan-2-line', 'info'));
        }
        $this->toast($final ? 'Report finalised and released.' : 'Report saved as draft.');
    }

    public function cancel(): void
    {
        abort_unless(auth()->user()->canAny(['radiology.order', 'radiology.perform']), 403);
        abort_unless(in_array($this->radiologyOrder->status, ['ordered', 'scheduled']), 422);
        $this->radiologyOrder->update(['status' => 'cancelled']);
        $note = app(DiagnosticsService::class)->unbill($this->radiologyOrder, 'Imaging: '.$this->radiologyOrder->test->name);
        $this->toast($note ?? 'Order cancelled and taken off the bill.', 'warning');
    }

    public function with(): array
    {
        $viewer = hospital()->setting('pacs_viewer_url');

        return [
            'o' => $this->radiologyOrder->load(['patient', 'doctor', 'test', 'attachments.uploader', 'performer', 'radiologist', 'orderedBy']),
            'pacsUrl' => $viewer && $this->radiologyOrder->study_instance_uid ? str_replace('{uid}', urlencode($this->radiologyOrder->study_instance_uid), $viewer) : null,
        ];
    }
}; ?>

<div>
    <x-page-header :title="$o->test->name" :subtitle="$o->order_no" :breadcrumbs="['Imaging Orders' => route('tenant.radiology.orders')]">
        <x-status :value="$o->status" />
        @if ($pacsUrl)<a href="{{ $pacsUrl }}" target="_blank" class="btn btn-sm btn-light-info"><i class="ri-eye-line me-1"></i>Open in PACS viewer</a>@endif
        @if (in_array($o->status, ['reported', 'approved']))<a href="{{ route('tenant.radiology.report', $o->id) }}" target="_blank" class="btn btn-sm btn-primary"><i class="ri-file-pdf-2-line me-1"></i>Report</a>@endif
        @if (in_array($o->status, ['ordered', 'scheduled']))<button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Cancel this study?', () => $wire.cancel())">Cancel</button>@endif
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body fs-13">
                    <h6><a href="{{ route('tenant.patients.show', $o->patient) }}" wire:navigate>{{ $o->patient->full_name }}</a></h6>
                    <p class="text-muted mb-2">{{ $o->patient->uhid }} · {{ $o->patient->age_gender }}</p>
                    <dl class="row mb-0">
                        <dt class="col-5">Modality</dt><dd class="col-7">{{ \App\Models\RadiologyTest::MODALITIES[$o->test->modality] ?? $o->test->modality }}</dd>
                        <dt class="col-5">Body part</dt><dd class="col-7">{{ $o->test->body_part ?: '—' }}</dd>
                        <dt class="col-5">Referred by</dt><dd class="col-7">{{ $o->doctor?->display_name ?? 'Self' }}</dd>
                        <dt class="col-5">Priority</dt><dd class="col-7">{{ strtoupper($o->priority) }}</dd>
                        <dt class="col-5">Ordered</dt><dd class="col-7">{{ fmt_datetime($o->created_at) }}</dd>
                        <dt class="col-5">Performed</dt><dd class="col-7">{{ fmt_datetime($o->performed_at) }} {{ $o->performer?->name }}</dd>
                        <dt class="col-5">Reported</dt><dd class="col-7">{{ fmt_datetime($o->reported_at) }} {{ $o->radiologist?->name }}</dd>
                    </dl>
                    @if ($o->clinical_history)<div class="mt-2"><strong>Clinical history:</strong> {{ $o->clinical_history }}</div>@endif
                    @if ($o->test->preparation)<div class="alert alert-info py-2 mt-2 mb-0">Preparation: {{ $o->test->preparation }}</div>@endif
                </div>
            </div>

            @can('radiology.perform')
                <div class="card mb-0">
                    <div class="card-header"><h6 class="card-title mb-0">Technician</h6></div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <input type="datetime-local" class="form-control" wire:model="scheduled_at">
                            <button class="btn btn-light-primary" wire:click="schedule">Schedule</button>
                        </div>
                        <x-form.textarea label="Technician notes" model="technician_notes" rows="2" />
                        <x-form.input label="DICOM Study Instance UID (PACS)" model="study_instance_uid" placeholder="1.2.840.113619...." />
                        <button class="btn btn-info w-100" wire:click="performed"><i class="ri-checkbox-circle-line me-1"></i>Save / mark performed</button>
                    </div>
                </div>
            @endcan
        </div>

        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-image-2-line me-1"></i>Images &amp; DICOM files</h6></div>
                <div class="card-body">
                    @can('radiology.perform')
                        <div class="d-flex gap-2 mb-3">
                            <input type="file" class="form-control @error('files.*') is-invalid @enderror" wire:model="files" multiple accept=".dcm,image/*,application/pdf,application/dicom">
                            <button class="btn btn-primary text-nowrap" wire:click="uploadFiles" wire:loading.attr="disabled"><i class="ri-upload-2-line me-1"></i>Upload</button>
                        </div>
                        <div wire:loading wire:target="files" class="fs-12 text-primary mb-2">Uploading…</div>
                        @error('files')<div class="text-danger fs-12 mb-2">Choose at least one image or DICOM file first.</div>@enderror
                        @error('files.*')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
                    @endcan
                    <div class="row g-2">
                        @forelse ($o->attachments as $a)
                            <div class="col-6 col-md-3" wire:key="ra-{{ $a->id }}">
                                <div class="border rounded p-2 h-100 text-center position-relative">
                                    @if ($a->type === 'image')
                                        <a href="{{ route('files.show', ['path' => $a->file_path]) }}" target="_blank"><img src="{{ route('files.show', ['path' => $a->file_path]) }}" class="img-fluid rounded mb-1" style="max-height: 110px;" alt=""></a>
                                    @else
                                        <a title="Open file" aria-label="Open file" href="{{ route('files.show', ['path' => $a->file_path]) }}" target="_blank" class="d-block py-3"><i class="{{ $a->type === 'dicom' ? 'ri-file-search-line' : 'ri-file-pdf-2-line' }} fs-1"></i></a>
                                    @endif
                                    <div class="fs-11 text-truncate">{{ $a->file_name }}</div>
                                    <div class="fs-11 text-muted">{{ strtoupper($a->type) }} · {{ human_bytes($a->size) }}</div>
                                    @can('radiology.perform')<button title="Delete file" aria-label="Delete file" class="btn btn-sm btn-link text-danger position-absolute top-0 end-0 p-1" x-on:click="$confirm('Delete file?', () => $wire.deleteAttachment({{ $a->id }}))"><i class="ri-close-line"></i></button>@endcan
                                </div>
                            </div>
                        @empty
                            <div class="col-12 text-muted text-center py-3">No images uploaded yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-file-text-line me-1"></i>Radiologist report</h6></div>
                <div class="card-body">
                    @can('radiology.report')
                        @if ($o->status !== 'approved')
                            <x-form.textarea label="Findings" model="findings" rows="6" required />
                            <x-form.textarea label="Impression" model="impression" rows="3" required />
                            <div class="d-flex gap-2 justify-content-end">
                                <button class="btn btn-light-primary" wire:click="saveReport(false)">Save draft</button>
                                <button class="btn btn-success" x-on:click="$confirm('Finalise and release this report?', () => $wire.saveReport(true), { color: 'success', confirmText: 'Finalise' })">Finalise &amp; sign</button>
                            </div>
                        @endif
                    @endcan
                    @if ($o->findings && (! auth()->user()->can('radiology.report') || $o->status === 'approved'))
                        <h6>Findings</h6><p class="fs-13">{!! nl2br(e($o->findings)) !!}</p>
                        <h6>Impression</h6><p class="fs-13 fw-semibold mb-0">{!! nl2br(e($o->impression)) !!}</p>
                    @elseif (! $o->findings && ! auth()->user()->can('radiology.report'))
                        <p class="text-muted mb-0">Report pending.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
