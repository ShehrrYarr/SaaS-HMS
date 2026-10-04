<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\OpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Patients')] class extends Component
{
    use WithTable;

    protected array $sortable = ['uhid', 'first_name', 'created_at', 'date_of_birth'];

    protected string $defaultSort = 'created_at';

    #[Url]
    public string $gender = '';

    #[Url]
    public string $blood = '';

    #[Url]
    public string $insured = '';

    public bool $showQuick = false;

    public array $quick = [];

    public ?int $createdId = null;

    public ?int $opdDoctor = null;

    public function openQuick(): void
    {
        $this->authorize('patients.create');
        $this->quick = ['first_name' => '', 'last_name' => '', 'gender' => 'male', 'age' => '', 'phone' => '', 'chief_complaint' => ''];
        $this->createdId = null;
        $this->opdDoctor = null;
        $this->resetValidation();
        $this->showQuick = true;
    }

    public function saveQuick(OpdService $opd): void
    {
        $this->authorize('patients.create');
        $data = $this->validate([
            'quick.first_name' => 'required|string|max:80',
            'quick.last_name' => 'nullable|string|max:80',
            'quick.gender' => 'required|in:male,female,other',
            'quick.age' => 'required|integer|min:0|max:130',
            'quick.phone' => 'nullable|string|max:30',
            'quick.chief_complaint' => 'nullable|string|max:200',
            'opdDoctor' => 'nullable|integer',
        ], [], ['quick.first_name' => 'first name', 'quick.age' => 'age'])['quick'];

        $patient = Patient::create([
            'uhid' => Patient::generateUhid(),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?: null,
            'gender' => $data['gender'],
            'date_of_birth' => now()->subYears((int) $data['age'])->startOfYear()->toDateString(),
            'phone' => $data['phone'] ?: null,
            'registration_type' => 'quick',
            'registered_by' => auth()->id(),
        ]);

        $message = "Registered {$patient->full_name} – UHID {$patient->uhid}";
        if ($this->opdDoctor && hospital()->hasModule('opd') && auth()->user()->can('opd.create')) {
            $visit = $opd->createVisit($patient, Staff::doctors()->findOrFail($this->opdDoctor), ['chief_complaint' => $data['chief_complaint']]);
            $message .= " · OPD token #{$visit->token_no}";
        }

        $this->createdId = $patient->id;
        $this->showQuick = false;
        $this->toast($message);
    }

    public function delete(int $id): void
    {
        $this->authorize('patients.delete');
        Patient::findOrFail($id)->delete();
        $this->toast('Patient archived.', 'warning');
    }

    public function with(): array
    {
        $query = Patient::with('tpa')
            ->search($this->search)
            ->when($this->gender, fn ($q) => $q->where('gender', $this->gender))
            ->when($this->blood, fn ($q) => $q->where('blood_group', $this->blood))
            ->when($this->insured === 'yes', fn ($q) => $q->whereNotNull('tpa_id'))
            ->when($this->insured === 'no', fn ($q) => $q->whereNull('tpa_id'));

        return [
            'patients' => $this->applySort($query)->paginate($this->perPage),
            'doctors' => hospital()->hasModule('opd') ? Staff::doctors()->active()->orderBy('name')->get()->mapWithKeys(fn ($d) => [$d->id => $d->display_name.' – '.$d->specialization])->all() : [],
        ];
    }
}; ?>

<div>
    <x-page-header title="Patients" subtitle="Patient registry">
        @can('patients.create')
            <button class="btn btn-light-primary btn-sm" wire:click="openQuick"><i class="ri-flashlight-line me-1"></i>Quick Registration</button>
            <a href="{{ route('tenant.patients.create') }}" wire:navigate class="btn btn-primary btn-sm"><i class="ri-user-add-line me-1"></i>Full Registration</a>
        @endcan
    </x-page-header>

    <div class="card">
        <x-table-toolbar placeholder="Search UHID, name, phone, national ID...">
            <select class="form-select w-auto" wire:model.live="gender">
                <option value="">Any gender</option>
                @foreach (config('hms.genders') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select>
            <select class="form-select w-auto" wire:model.live="blood">
                <option value="">Blood group</option>
                @foreach (config('hms.blood_groups') as $bg)<option>{{ $bg }}</option>@endforeach
            </select>
            <select class="form-select w-auto" wire:model.live="insured">
                <option value="">Insured?</option>
                <option value="yes">Insured</option>
                <option value="no">Self-pay</option>
            </select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <x-th field="uhid" :sort="$sortField" :dir="$sortDirection">UHID</x-th>
                        <x-th field="first_name" :sort="$sortField" :dir="$sortDirection">Patient</x-th>
                        <x-th field="date_of_birth" :sort="$sortField" :dir="$sortDirection">Age / Sex</x-th>
                        <th>Phone</th><th>Blood</th><th>Insurance</th>
                        <x-th field="created_at" :sort="$sortField" :dir="$sortDirection">Registered</x-th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($patients as $p)
                        <tr wire:key="p-{{ $p->id }}" class="{{ $createdId === $p->id ? 'table-success' : '' }}">
                            <td class="fw-semibold text-nowrap">{{ $p->uhid }}</td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <x-avatar :src="$p->photoUrl()" :name="$p->full_name" size="sm" />
                                    <div>
                                        <a href="{{ route('tenant.patients.show', $p) }}" wire:navigate class="fw-semibold">{{ $p->full_name }}</a>
                                        @if ($p->registration_type === 'quick')<span class="badge bg-warning-subtle text-warning ms-1">Quick</span>@endif
                                    </div>
                                </div>
                            </td>
                            <td>{{ $p->age_gender }}</td>
                            <td>{{ $p->phone ?: '—' }}</td>
                            <td>{{ $p->blood_group ?: '—' }}</td>
                            <td>{{ $p->tpa?->name ?? 'Self-pay' }}</td>
                            <td>{{ fmt_date($p->created_at) }}</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tenant.patients.show', $p) }}" wire:navigate class="btn btn-sm btn-light-primary icon-btn-sm" title="Open EMR"><i class="ri-folder-user-line"></i></a>
                                @can('patients.update')
                                    <a href="{{ route('tenant.patients.edit', $p) }}" wire:navigate class="btn btn-sm btn-light-info icon-btn-sm" title="Edit"><i class="ri-edit-line"></i></a>
                                @endcan
                                <a href="{{ route('tenant.patients.card', $p->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm" title="Print ID card"><i class="ri-bank-card-line"></i></a>
                                @can('patients.delete')
                                    <button class="btn btn-sm btn-light-danger icon-btn-sm" title="Archive" x-on:click="$confirm('Archive {{ addslashes($p->full_name) }}?', () => $wire.delete({{ $p->id }}))"><i class="ri-delete-bin-line"></i></button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No patients match your filters." icon="ri-user-search-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $patients->links() }}</div>
    </div>

    <x-modal wire:model="showQuick" title="Quick registration" size="lg">
        <p class="text-muted fs-13">Minimal details for walk-ins and emergencies. A UHID is generated instantly; complete the profile later.</p>
        <div class="row">
            <x-form.input class="col-md-6" label="First name" model="quick.first_name" required />
            <x-form.input class="col-md-6" label="Last name" model="quick.last_name" />
            <x-form.select class="col-md-4" label="Gender" model="quick.gender" :options="config('hms.genders')" :placeholder="false" required />
            <x-form.input class="col-md-4" label="Age (years)" model="quick.age" type="number" min="0" required />
            <x-form.input class="col-md-4" label="Phone" model="quick.phone" />
            @if ($doctors && auth()->user()->can('opd.create'))
                <div class="col-12"><hr class="mt-0"><h6 class="mb-3">Send to OPD now <small class="text-muted fw-normal">(optional)</small></h6></div>
                <x-form.search-select class="col-md-6" label="Doctor" model="opdDoctor" :options="$doctors" placeholder="No OPD visit" />
                <x-form.input class="col-md-6" label="Chief complaint" model="quick.chief_complaint" />
            @endif
        </div>
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-primary" wire:click="saveQuick" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Register</button>
        </x-slot:footer>
    </x-modal>
</div>
