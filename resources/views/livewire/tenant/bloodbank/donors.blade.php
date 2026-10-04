<?php

use App\Livewire\Concerns\WithTable;
use App\Models\BloodBag;
use App\Models\BloodDonor;
use App\Services\BloodBankService;
use App\Support\Sequence;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Blood Donors')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'last_donation_date'];

    #[Url]
    public string $group = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public bool $showDonation = false;

    public ?int $donorId = null;

    public array $donation = [];

    public function create(): void
    {
        $this->authorize('bloodbank.manage');
        $this->editingId = null;
        $this->form = ['name' => '', 'gender' => 'male', 'date_of_birth' => '', 'blood_group' => 'O+', 'phone' => '', 'email' => '', 'address' => '', 'weight' => '', 'notes' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $d = BloodDonor::findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($d->only(['name', 'gender', 'blood_group', 'phone', 'email', 'address', 'weight', 'notes']))->map(fn ($v) => (string) $v)->all() + ['date_of_birth' => $d->date_of_birth?->toDateString() ?? ''];
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('bloodbank.manage');
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.gender' => 'required|in:male,female,other',
            'form.date_of_birth' => 'nullable|date|before:-17 years',
            'form.blood_group' => 'required|in:'.implode(',', config('hms.blood_groups')),
            'form.phone' => 'nullable|string|max:30',
            'form.email' => 'nullable|email',
            'form.address' => 'nullable|string|max:255',
            'form.weight' => 'nullable|numeric|min:30|max:250',
            'form.notes' => 'nullable|string|max:255',
        ], ['form.date_of_birth.before' => 'Donors must be at least 18 years old.'])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['is_eligible'] = ! isset($data['weight']) || (float) $data['weight'] >= 50;
        $this->editingId ? BloodDonor::findOrFail($this->editingId)->update($data) : BloodDonor::create($data + ['donor_no' => Sequence::code('donor', 'DNR', 4)]);
        $this->showForm = false;
        $this->toast('Donor saved.');
    }

    public function openDonation(int $id): void
    {
        $this->authorize('bloodbank.manage');
        $this->donorId = $id;
        $this->donation = ['component' => 'whole_blood', 'volume_ml' => 450, 'collected_at' => today()->toDateString(), 'storage_location' => ''];
        $this->resetValidation();
        $this->showDonation = true;
    }

    public function saveDonation(BloodBankService $bb): void
    {
        $this->authorize('bloodbank.manage');
        $this->validate([
            'donation.component' => 'required|in:'.implode(',', array_keys(BloodBag::COMPONENTS)),
            'donation.volume_ml' => 'required|integer|min:50|max:600',
            'donation.collected_at' => 'required|date|before_or_equal:today',
            'donation.storage_location' => 'nullable|string|max:50',
        ]);
        $bag = $bb->recordDonation(BloodDonor::findOrFail($this->donorId), $this->donation);
        $this->showDonation = false;
        $this->toast("Donation recorded – bag {$bag->bag_no} in quarantine pending screening.");
    }

    public function with(): array
    {
        $query = BloodDonor::withCount('bags')
            ->when($this->group, fn ($q) => $q->where('blood_group', $this->group))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('donor_no', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")));

        return ['donors' => $this->applySort($query)->paginate($this->perPage), 'groups' => config('hms.blood_groups'), 'components' => BloodBag::COMPONENTS];
    }
}; ?>

<div>
    <x-page-header title="Blood Donors" subtitle="Donor registry" :breadcrumbs="['Blood Bank' => route('tenant.bloodbank.inventory')]">
        @can('bloodbank.manage')<button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-user-add-line me-1"></i>Register donor</button>@endcan
    </x-page-header>
    <div class="card">
        <x-table-toolbar placeholder="Name, donor # or phone...">
            <select class="form-select w-auto" wire:model.live="group"><option value="">All groups</option>@foreach ($groups as $g)<option>{{ $g }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Donor #</th><x-th field="name" :sort="$sortField" :dir="$sortDirection">Name</x-th><th>Group</th><th>Phone</th><th>Donations</th><x-th field="last_donation_date" :sort="$sortField" :dir="$sortDirection">Last donation</x-th><th>Eligible</th><th></th></tr></thead>
                <tbody>
                    @forelse ($donors as $d)
                        @php $next = $d->last_donation_date?->copy()->addDays(90); $eligible = $d->is_eligible && (! $next || $next->lte(today())); @endphp
                        <tr wire:key="dn-{{ $d->id }}">
                            <td>{{ $d->donor_no }}</td><td class="fw-semibold">{{ $d->name }}<div class="fs-12 text-muted">{{ label($d->gender) }} {{ $d->date_of_birth ? '· '.$d->date_of_birth->age.'y' : '' }}</div></td>
                            <td class="text-danger fw-bold">{{ $d->blood_group }}</td><td>{{ $d->phone }}</td><td>{{ $d->bags_count }}</td><td>{{ fmt_date($d->last_donation_date) }}</td>
                            <td>@if ($eligible)<span class="badge bg-success-subtle text-success">Eligible</span>@else<span class="badge bg-warning-subtle text-warning">{{ $next ? 'From '.fmt_date($next) : 'No' }}</span>@endif</td>
                            <td class="text-end text-nowrap">
                                @can('bloodbank.manage')
                                    <button class="btn btn-sm btn-danger" wire:click="openDonation({{ $d->id }})" @disabled(! $eligible)><i class="ri-drop-line"></i> Donate</button>
                                    <button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $d->id }})"><i class="ri-edit-line"></i></button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No donors." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $donors->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit donor' : 'Register donor'">
        <div class="row">
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.select class="col-md-4" label="Blood group" model="form.blood_group" :options="array_combine($groups, $groups)" :placeholder="false" />
            <x-form.select class="col-md-4" label="Gender" model="form.gender" :options="config('hms.genders')" :placeholder="false" />
            <x-form.input class="col-md-4" label="Date of birth" model="form.date_of_birth" type="date" />
            <x-form.input class="col-md-4" label="Weight (kg)" model="form.weight" type="number" step="0.1" />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-md-6" label="Email" model="form.email" type="email" />
            <x-form.input class="col-12" label="Address" model="form.address" />
            <x-form.input class="col-12" label="Notes" model="form.notes" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showDonation" title="Record donation">
        <div class="row">
            <x-form.select class="col-md-6" label="Component" model="donation.component" :options="$components" :placeholder="false" />
            <x-form.input class="col-md-6" label="Volume (ml)" model="donation.volume_ml" type="number" />
            <x-form.input class="col-md-6" label="Collected" model="donation.collected_at" type="date" />
            <x-form.input class="col-md-6" label="Storage" model="donation.storage_location" />
        </div>
        @error('donation')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-danger" wire:click="saveDonation">Record donation</button></x-slot:footer>
    </x-modal>
</div>
