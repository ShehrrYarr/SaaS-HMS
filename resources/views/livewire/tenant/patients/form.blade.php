<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Patient;
use App\Models\Tpa;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Patient Registration')] class extends Component
{
    use Toasts, WithFileUploads;

    public ?Patient $patient = null;

    public array $form = [];

    public $photo;

    public bool $portal = false;

    public string $portal_email = '';

    public string $portal_password = '';

    public function mount(?Patient $patient = null): void
    {
        $this->patient = $patient?->exists ? $patient : null;
        $fields = ['first_name', 'last_name', 'gender', 'date_of_birth', 'blood_group', 'phone', 'email', 'national_id', 'marital_status', 'occupation', 'guardian_name',
            'address', 'city', 'state', 'country', 'postal_code', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
            'tpa_id', 'insurance_policy_no', 'insurance_valid_until', 'notes'];

        $this->form = array_fill_keys($fields, '');
        $this->form['gender'] = 'male';
        $this->form['country'] = hospital()->country ?? '';
        $this->form['city'] = hospital()->city ?? '';

        if ($this->patient) {
            foreach ($fields as $f) {
                $value = $this->patient->{$f};
                $this->form[$f] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
            }
            $this->portal = (bool) $this->patient->user_id;
            $this->portal_email = $this->patient->user?->email ?? (string) $this->patient->email;
        }
    }

    public function save()
    {
        $this->authorize($this->patient ? 'patients.update' : 'patients.create');

        $data = $this->validate([
            'form.first_name' => 'required|string|max:80',
            'form.last_name' => 'nullable|string|max:80',
            'form.gender' => 'required|in:male,female,other',
            'form.date_of_birth' => 'nullable|date|before_or_equal:today',
            'form.blood_group' => ['nullable', Rule::in(config('hms.blood_groups'))],
            'form.phone' => 'nullable|string|max:30',
            'form.email' => 'nullable|email|max:150',
            'form.national_id' => 'nullable|string|max:50',
            'form.marital_status' => 'nullable|in:single,married,divorced,widowed',
            'form.occupation' => 'nullable|string|max:100',
            'form.guardian_name' => 'nullable|string|max:120',
            'form.address' => 'nullable|string|max:255',
            'form.city' => 'nullable|string|max:100',
            'form.state' => 'nullable|string|max:100',
            'form.country' => 'nullable|string|max:100',
            'form.postal_code' => 'nullable|string|max:20',
            'form.emergency_contact_name' => 'nullable|string|max:120',
            'form.emergency_contact_phone' => 'nullable|string|max:30',
            'form.emergency_contact_relation' => 'nullable|string|max:50',
            'form.tpa_id' => ['nullable', Rule::exists('tpas', 'id')->where('hospital_id', hospital()->id)],
            'form.insurance_policy_no' => 'nullable|string|max:100',
            'form.insurance_valid_until' => 'nullable|date',
            'form.notes' => 'nullable|string|max:2000',
            'photo' => 'nullable|image|max:2048',
            'portal_email' => [Rule::requiredIf($this->portal), 'nullable', 'email',
                Rule::unique('users', 'email')->where('hospital_id', hospital()->id)->ignore($this->patient?->user_id)],
            'portal_password' => [Rule::requiredIf($this->portal && ! $this->patient?->user_id && blank($this->form['phone'])), 'nullable', 'min:8'],
        ])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->patient) {
            $this->patient->update($data + ['registration_type' => 'full']);
            $patient = $this->patient;
        } else {
            $patient = Patient::create($data + ['uhid' => Patient::generateUhid(), 'registration_type' => 'full', 'registered_by' => auth()->id()]);
        }

        if ($this->photo) {
            $patient->update(['photo_path' => $this->photo->store(hospital()->storagePath("patients/{$patient->id}"), 'local')]);
        }

        $this->syncPortalAccess($patient);

        session()->flash('success', ($this->patient ? 'Patient updated' : 'Patient registered')." – UHID {$patient->uhid}");

        return $this->redirect(route('tenant.patients.show', $patient), navigate: true);
    }

    protected function syncPortalAccess(Patient $patient): void
    {
        if (! $this->portal) {
            if ($patient->user_id) {
                $patient->user?->update(['status' => 'inactive']);
            }

            return;
        }

        $user = $patient->user ?? new User;
        $user->fill(['name' => $patient->full_name, 'email' => $this->portal_email, 'phone' => $patient->phone, 'status' => 'active']);
        if ($this->portal_password) {
            $user->password = $this->portal_password;
        } elseif (! $user->exists) {
            $user->password = Str::random(32); // OTP-only login until a password is set
        }
        $user->hospital_id = hospital()->id;
        $user->save();
        if (! $user->hasRole('Patient')) {
            $user->assignRole('Patient');
        }
        $patient->update(['user_id' => $user->id]);
    }

    public function rendering($view): void
    {
        $view->title($this->patient ? 'Edit Patient' : 'Patient Registration');
    }

    public function with(): array
    {
        // Compare the last 10 digits so "0300 2000001" and "+923002000001" count as the same number.
        $digits = substr(preg_replace('/\D/', '', (string) ($this->form['phone'] ?? '')), -10);

        return [
            'tpas' => Tpa::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'duplicates' => strlen($digits) >= 7
                ? Patient::whereRaw("REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ?", ["%{$digits}"])->when($this->patient, fn ($q) => $q->whereKeyNot($this->patient->id))->limit(5)->get()
                : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$patient ? 'Edit Patient' : 'Patient Registration'" :subtitle="$patient ? $patient->uhid : 'Advanced registration'" :breadcrumbs="['Patients' => route('tenant.patients.index')]" />

    <form wire:submit="save">
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0"><i class="ri-user-line me-1"></i>Personal information</h5></div>
                    <div class="card-body row">
                        <x-form.input class="col-md-5" label="First name" model="form.first_name" required />
                        <x-form.input class="col-md-4" label="Last name" model="form.last_name" />
                        <x-form.select class="col-md-3" label="Gender" model="form.gender" :options="config('hms.genders')" :placeholder="false" required />
                        <x-form.input class="col-md-4" label="Date of birth" model="form.date_of_birth" type="date" />
                        <x-form.select class="col-md-4" label="Blood group" model="form.blood_group" :options="array_combine(config('hms.blood_groups'), config('hms.blood_groups'))" />
                        <x-form.select class="col-md-4" label="Marital status" model="form.marital_status" :options="['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'widowed' => 'Widowed']" />
                        <x-form.input class="col-md-4" label="National ID / Passport" model="form.national_id" />
                        <x-form.input class="col-md-4" label="Occupation" model="form.occupation" />
                        <x-form.input class="col-md-4" label="Guardian / Father / Spouse" model="form.guardian_name" />
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0"><i class="ri-contacts-line me-1"></i>Contact</h5></div>
                    <div class="card-body row">
                        <x-form.input class="col-md-6" label="Phone" model="form.phone" live />
                        <x-form.input class="col-md-6" label="Email" model="form.email" type="email" />
                        @if ($duplicates->isNotEmpty())
                            <div class="col-12">
                                <div class="alert alert-warning py-2">
                                    <strong>Possible duplicates:</strong>
                                    @foreach ($duplicates as $d)
                                        <a href="{{ route('tenant.patients.show', $d) }}" wire:navigate class="ms-2">{{ $d->full_name }} ({{ $d->uhid }})</a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <x-form.input class="col-12" label="Address" model="form.address" />
                        <x-form.input class="col-md-4" label="City" model="form.city" />
                        <x-form.input class="col-md-3" label="State" model="form.state" />
                        <x-form.input class="col-md-3" label="Country" model="form.country" />
                        <x-form.input class="col-md-2" label="Postal code" model="form.postal_code" />
                        <x-form.input class="col-md-5" label="Emergency contact" model="form.emergency_contact_name" />
                        <x-form.input class="col-md-4" label="Emergency phone" model="form.emergency_contact_phone" />
                        <x-form.input class="col-md-3" label="Relation" model="form.emergency_contact_relation" />
                    </div>
                </div>
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0"><i class="ri-shield-cross-line me-1"></i>Insurance (TPA)</h5></div>
                    <div class="card-body row">
                        <x-form.select class="col-md-5" label="Insurance company / TPA" model="form.tpa_id" :options="$tpas" placeholder="Self-pay" />
                        <x-form.input class="col-md-4" label="Policy number" model="form.insurance_policy_no" />
                        <x-form.input class="col-md-3" label="Valid until" model="form.insurance_valid_until" type="date" />
                        <x-form.textarea class="col-12 mb-0" label="Notes" model="form.notes" rows="2" />
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Photo</h5></div>
                    <div class="card-body text-center">
                        <x-avatar :src="$photo ? $photo->temporaryUrl() : $patient?->photoUrl()" :name="trim(($form['first_name'] ?? '').' '.($form['last_name'] ?? '')) ?: 'New'" size="xl" class="mb-3" />
                        <input type="file" class="form-control @error('photo') is-invalid @enderror" wire:model="photo" accept="image/*">
                        @error('photo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                @if (hospital()->hasModule('portal'))
                    <div class="card">
                        <div class="card-header"><h5 class="card-title mb-0">Patient portal access</h5></div>
                        <div class="card-body">
                            <x-form.switch label="Enable portal login" model="portal" live />
                            @if ($portal)
                                <x-form.input label="Portal email" model="portal_email" type="email" required />
                                <x-form.input label="Password" model="portal_password" type="password" :hint="$patient?->user_id ? 'Leave blank to keep the current password.' : 'Optional if the patient will sign in with phone OTP.'" />
                            @endif
                        </div>
                    </div>
                @endif
                <button class="btn btn-primary w-100" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>{{ $patient ? 'Save changes' : 'Register patient' }}</button>
            </div>
        </div>
    </form>
</div>
