<?php

use App\Livewire\Concerns\GuardsDemo;
use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Hospital Profile')] class extends Component
{
    use GuardsDemo, Toasts, WithFileUploads;

    public array $form = [];

    public array $settings = [];

    public $logo;

    public $certificate;

    public string $certPassword = '';

    public function mount(): void
    {
        $h = hospital();
        $this->form = collect($h->only(['name', 'email', 'phone', 'address', 'city', 'state', 'country', 'postal_code', 'registration_no', 'tax_no', 'timezone', 'tax_label', 'tax_rate', 'uhid_prefix']))->map(fn ($v) => (string) $v)->all();
        $this->settings = [
            'invoice_footer' => (string) $h->setting('invoice_footer', ''),
            'report_footer' => (string) $h->setting('report_footer', ''),
            'pacs_viewer_url' => (string) $h->setting('pacs_viewer_url', ''),
            'prescription_header' => (string) $h->setting('prescription_header', ''),
        ];
    }

    public function save(): void
    {
        $this->authorize('settings.manage');
        if ($this->demoLocked('Editing the hospital profile')) {
            return;
        }
        $data = $this->validate([
            'form.name' => 'required|string|max:150',
            'form.email' => 'nullable|email',
            'form.phone' => 'nullable|string|max:30',
            'form.address' => 'nullable|string|max:255',
            'form.city' => 'nullable|string|max:100',
            'form.state' => 'nullable|string|max:100',
            'form.country' => 'nullable|string|max:100',
            'form.postal_code' => 'nullable|string|max:20',
            'form.registration_no' => 'nullable|string|max:100',
            'form.tax_no' => 'nullable|string|max:100',
            'form.timezone' => 'required|timezone',
            'form.tax_label' => 'required|string|max:30',
            'form.tax_rate' => 'required|numeric|min:0|max:100',
            'form.uhid_prefix' => 'nullable|alpha_num|max:10',
            'settings.invoice_footer' => 'nullable|string|max:255',
            'settings.report_footer' => 'nullable|string|max:255',
            'settings.pacs_viewer_url' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                // {uid} is a placeholder for the study id, so check the address with a sample id in it.
                if (! filter_var(str_replace('{uid}', '1.2.840.0', $value), FILTER_VALIDATE_URL)) {
                    $fail('The PACS viewer URL must be a web address (use {uid} where the study ID goes).');
                }
            }],
            'settings.prescription_header' => 'nullable|string|max:255',
            'logo' => 'nullable|image|max:2048',
        ])['form'];

        $h = hospital();
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if ($this->logo) {
            $data['logo_path'] = $this->logo->store($h->storagePath('branding'), 'local');
        }
        $data['settings'] = array_merge($h->settings ?? [], $this->settings);
        $h->update($data);
        $this->logo = null;
        $this->toast('Hospital profile saved.');
    }

    public function uploadCertificate(): void
    {
        $this->authorize('settings.manage');
        if ($this->demoLocked('Installing certificates')) {
            return;
        }
        $this->validate(['certificate' => 'required|file|max:100', 'certPassword' => 'required|string|max:200']);
        $content = file_get_contents($this->certificate->getRealPath());
        $certs = [];
        if (! openssl_pkcs12_read($content, $certs, $this->certPassword)) {
            $this->addError('certificate', 'Could not open the certificate with this password (expecting a .p12 / .pfx file).');

            return;
        }
        $path = $this->certificate->storeAs(hospital()->storagePath('certificates'), 'lab-signing-'.Str::random(8).'.p12', 'local');
        hospital()->update(['lab_cert_path' => $path, 'lab_cert_password' => $this->certPassword]);
        $this->reset('certificate', 'certPassword');
        $info = openssl_x509_parse($certs['cert']);
        $this->toast('Signing certificate installed for '.($info['subject']['CN'] ?? 'hospital').'.');
    }

    public function removeCertificate(): void
    {
        $this->authorize('settings.manage');
        hospital()->update(['lab_cert_path' => null, 'lab_cert_password' => null]);
        $this->toast('Certificate removed.', 'warning');
    }

    public function with(): array
    {
        return [
            'timezones' => collect(timezone_identifiers_list())->mapWithKeys(fn ($t) => [$t => $t])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Hospital Profile" subtitle="Settings" />
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Details</h6></div>
                <div class="card-body row">
                    <x-form.input class="col-md-8" label="Hospital name" model="form.name" required />
                    <x-form.input class="col-md-4" label="UHID prefix" model="form.uhid_prefix" :placeholder="hospital()->code" hint="e.g. CGH → CGH-26-000123" />
                    <x-form.input class="col-md-6" label="Email" model="form.email" type="email" />
                    <x-form.input class="col-md-6" label="Phone" model="form.phone" />
                    <x-form.input class="col-12" label="Address" model="form.address" />
                    <x-form.input class="col-md-3" label="City" model="form.city" />
                    <x-form.input class="col-md-3" label="State" model="form.state" />
                    <x-form.input class="col-md-3" label="Country" model="form.country" />
                    <x-form.input class="col-md-3" label="Postal code" model="form.postal_code" />
                    <x-form.input class="col-md-6" label="Registration / license no." model="form.registration_no" />
                    <x-form.input class="col-md-6" label="Tax / GST / VAT no." model="form.tax_no" />
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Localisation &amp; tax</h6></div>
                <div class="card-body row">
                    <x-form.search-select class="col-md-8" label="Timezone" model="form.timezone" :options="$timezones" />
                    <x-form.input class="col-md-2" label="Tax label" model="form.tax_label" placeholder="GST / VAT" />
                    <x-form.input class="col-md-2" label="Default tax %" model="form.tax_rate" type="number" step="0.01" />
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Documents &amp; integrations</h6></div>
                <div class="card-body row">
                    <x-form.input class="col-12" label="Prescription header line" model="settings.prescription_header" placeholder="e.g. 24/7 Emergency: +1 555 0100" />
                    <x-form.input class="col-md-6" label="Invoice footer" model="settings.invoice_footer" />
                    <x-form.input class="col-md-6" label="Lab report footer" model="settings.report_footer" />
                    <x-form.input class="col-12" label="PACS viewer URL" model="settings.pacs_viewer_url" placeholder="https://pacs.example.com/viewer?StudyInstanceUIDs={uid}" hint="{uid} is replaced by the DICOM Study Instance UID (e.g. OHIF viewer)." />
                    <div class="col-12"><button class="btn btn-primary" wire:click="save" wire:loading.attr="disabled">Save settings</button></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Logo</h6></div>
                <div class="card-body text-center">
                    <img src="{{ $logo ? $logo->temporaryUrl() : hospital()->logoUrl() }}" class="hms-logo mb-3" height="60" alt="">
                    <input type="file" class="form-control" wire:model="logo" accept="image/*">
                    @error('logo')<div class="text-danger fs-12">{{ $message }}</div>@enderror
                    <p class="fs-12 text-muted mt-2 mb-0">Used on the sidebar, invoices, prescriptions and reports. Save to apply.</p>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-quill-pen-line me-1"></i>Lab report digital signing</h6></div>
                <div class="card-body">
                    @if (hospital()->lab_cert_path)
                        <div class="alert alert-success py-2">Certificate installed. Approved reports can be downloaded as cryptographically signed PDFs.</div>
                        <button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Remove the signing certificate?', () => $wire.removeCertificate())">Remove certificate</button>
                    @else
                        <p class="fs-13 text-muted">Upload a PKCS#12 (.p12/.pfx) certificate to sign lab report PDFs (Adobe “Signed by”). Reports always include the pathologist's signature image &amp; a QR verification code.</p>
                        <input type="file" class="form-control mb-2" wire:model="certificate" accept=".p12,.pfx">
                        <input type="password" class="form-control mb-2" placeholder="Certificate password" wire:model="certPassword">
                        @error('certificate')<div class="text-danger fs-12 mb-2">{{ $message }}</div>@enderror
                        <button class="btn btn-sm btn-primary" wire:click="uploadCertificate">Install certificate</button>
                    @endif
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-body fs-13">
                    <dl class="row mb-0">
                        <dt class="col-5">Hospital code</dt><dd class="col-7">{{ hospital()->code }}</dd>
                        <dt class="col-5">Login URL</dt><dd class="col-7 text-break">{{ route('tenant.login') }}</dd>
                        <dt class="col-5">Portal URL</dt><dd class="col-7 text-break">{{ route('portal.login') }}</dd>
                        <dt class="col-5">Storage used</dt><dd class="col-7">{{ human_bytes(hospital()->storage_used_bytes) }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
