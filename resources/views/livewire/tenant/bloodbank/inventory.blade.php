<?php

use App\Livewire\Concerns\WithTable;
use App\Models\BloodBag;
use App\Services\BloodBankService;
use App\Support\Sequence;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Blood Bank')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'expires_at';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['expires_at', 'collected_at'];

    #[Url]
    public string $group = '';

    #[Url]
    public string $component = '';

    #[Url]
    public string $status = 'available';

    public bool $showForm = false;

    public array $form = [];

    public ?int $screeningId = null;

    public array $screening = [];

    public bool $showScreening = false;

    public function create(): void
    {
        $this->authorize('bloodbank.manage');
        $this->form = ['blood_group' => 'O+', 'component' => 'prbc', 'volume_ml' => 300, 'collected_at' => today()->toDateString(), 'source' => 'external', 'storage_location' => '', 'bag_no' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('bloodbank.manage');
        $this->validate([
            'form.blood_group' => 'required|in:'.implode(',', config('hms.blood_groups')),
            'form.component' => 'required|in:'.implode(',', array_keys(BloodBag::COMPONENTS)),
            'form.volume_ml' => 'required|integer|min:20|max:600',
            'form.collected_at' => 'required|date|before_or_equal:today',
            'form.storage_location' => 'nullable|string|max:50',
            'form.bag_no' => 'nullable|string|max:30',
        ]);
        $collected = Carbon::parse($this->form['collected_at']);
        BloodBag::create([
            'bag_no' => $this->form['bag_no'] ?: Sequence::code('blood-bag', 'BAG', 5),
            'blood_group' => $this->form['blood_group'], 'component' => $this->form['component'], 'volume_ml' => $this->form['volume_ml'],
            'source' => 'external', 'collected_at' => $collected->toDateString(),
            'expires_at' => $collected->copy()->addDays(BloodBag::SHELF_LIFE[$this->form['component']])->toDateString(),
            'status' => 'available', 'storage_location' => $this->form['storage_location'] ?: null,
            'screening' => ['hiv' => 'negative', 'hbv' => 'negative', 'hcv' => 'negative', 'syphilis' => 'negative', 'malaria' => 'negative'],
        ]);
        $this->showForm = false;
        $this->toast('Blood unit added to stock.');
    }

    public function openScreening(int $id): void
    {
        $this->authorize('bloodbank.manage');
        $bag = BloodBag::whereIn('status', ['quarantine', 'available'])->findOrFail($id);
        $this->screeningId = $id;
        $this->screening = array_merge(array_fill_keys(BloodBag::SCREENING_TESTS, 'pending'), array_intersect_key($bag->screening ?? [], array_flip(BloodBag::SCREENING_TESTS)));
        $this->resetValidation();
        $this->showScreening = true;
    }

    public function saveScreening(): void
    {
        $this->authorize('bloodbank.manage');
        $rules = collect(BloodBag::SCREENING_TESTS)->mapWithKeys(fn ($t) => ["screening.{$t}" => 'required|in:pending,negative,reactive'])->all();
        $this->screening = $this->validate($rules)['screening'];
        $bag = BloodBag::whereIn('status', ['quarantine', 'available'])->findOrFail($this->screeningId);
        $status = in_array('reactive', $this->screening, true) ? 'discarded' : (in_array('pending', $this->screening, true) ? 'quarantine' : 'available');
        $bag->update(['screening' => $this->screening, 'status' => $status]);
        $this->showScreening = false;
        $this->toast($status === 'available' ? 'Screening complete – unit released to stock.' : ($status === 'discarded' ? 'Reactive result – unit discarded.' : 'Screening saved.'), $status === 'discarded' ? 'warning' : 'success');
    }

    public function discard(int $id): void
    {
        $this->authorize('bloodbank.manage');
        BloodBag::whereIn('status', ['available', 'quarantine', 'expired'])->findOrFail($id)->update(['status' => 'discarded']);
    }

    public function expire(BloodBankService $bb): void
    {
        $this->authorize('bloodbank.manage');
        $this->toast($bb->expireOld().' unit(s) marked expired.');
    }

    public function with(): array
    {
        $query = BloodBag::with('donor')
            ->when($this->group, fn ($q) => $q->where('blood_group', $this->group))
            ->when($this->component, fn ($q) => $q->where('component', $this->component))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where('bag_no', 'like', "%{$this->search}%"));

        $matrix = BloodBag::where('status', 'available')->whereDate('expires_at', '>=', today())->selectRaw('blood_group, component, count(*) c')->groupBy('blood_group', 'component')->get()
            ->groupBy('blood_group')->map(fn ($rows) => $rows->pluck('c', 'component'));

        return [
            'bags' => $this->applySort($query)->paginate($this->perPage),
            'matrix' => $matrix,
            'groups' => config('hms.blood_groups'),
            'components' => BloodBag::COMPONENTS,
            'expiringSoon' => BloodBag::where('status', 'available')->whereBetween('expires_at', [today(), today()->addDays(3)])->count(),
            'quarantine' => BloodBag::where('status', 'quarantine')->count(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Blood Bank" subtitle="Inventory">
        <a href="{{ route('tenant.bloodbank.donors') }}" wire:navigate class="btn btn-light-primary btn-sm"><i class="ri-user-heart-line me-1"></i>Donors</a>
        <a href="{{ route('tenant.bloodbank.requests') }}" wire:navigate class="btn btn-light-info btn-sm"><i class="ri-hand-heart-line me-1"></i>Requests</a>
        @can('bloodbank.manage')
            <button class="btn btn-light-warning btn-sm" wire:click="expire">Run expiry check</button>
            <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-add-line me-1"></i>Add unit</button>
        @endcan
    </x-page-header>

    @if ($expiringSoon || $quarantine)
        <div class="alert alert-warning py-2">{{ $expiringSoon }} unit(s) expire within 3 days · {{ $quarantine }} unit(s) awaiting screening.</div>
    @endif

    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Available stock (units)</h6></div>
        <div class="table-responsive">
            <table class="table table-bordered text-center mb-0">
                <thead class="table-light"><tr><th class="text-start">Component</th>@foreach ($groups as $g)<th class="text-danger">{{ $g }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach ($components as $k => $l)
                        <tr><td class="text-start">{{ $l }}</td>
                            @foreach ($groups as $g)
                                @php $n = $matrix[$g][$k] ?? 0; @endphp
                                <td class="{{ $n === 0 ? 'text-muted' : ($n < 2 ? 'bg-warning-subtle fw-semibold' : 'bg-success-subtle fw-semibold') }}">{{ $n }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Bag number...">
            <select class="form-select w-auto" wire:model.live="group"><option value="">All groups</option>@foreach ($groups as $g)<option>{{ $g }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="component"><option value="">All components</option>@foreach ($components as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="status"><option value="">Any status</option>@foreach (['quarantine', 'available', 'reserved', 'issued', 'expired', 'discarded'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover mb-0">
                <thead class="table-light"><tr><th>Bag</th><th>Group</th><th>Component</th><th>Volume</th><th>Source</th><x-th field="collected_at" :sort="$sortField" :dir="$sortDirection">Collected</x-th><x-th field="expires_at" :sort="$sortField" :dir="$sortDirection">Expires</x-th><th>Location</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($bags as $b)
                        <tr wire:key="bag-{{ $b->id }}">
                            <td class="fw-semibold">{{ $b->bag_no }}</td><td class="text-danger fw-bold">{{ $b->blood_group }}</td><td>{{ $components[$b->component] ?? $b->component }}</td><td>{{ $b->volume_ml }} ml</td>
                            <td class="fs-12">{{ $b->donor ? 'Donor '.$b->donor->donor_no : 'External' }}</td><td>{{ fmt_date($b->collected_at) }}</td>
                            <td class="{{ $b->expires_at->lt(today()->addDays(3)) ? 'text-danger fw-semibold' : '' }}">{{ fmt_date($b->expires_at) }}</td>
                            <td>{{ $b->storage_location ?? '—' }}</td><td><x-status :value="$b->status" /></td>
                            <td class="text-end text-nowrap">
                                @can('bloodbank.manage')
                                    @if (in_array($b->status, ['quarantine', 'available']))<button class="btn btn-sm btn-light-info icon-btn-sm" title="Screening" wire:click="openScreening({{ $b->id }})"><i class="ri-microscope-line"></i></button>@endif
                                    @if (in_array($b->status, ['quarantine', 'available', 'expired']))<button class="btn btn-sm btn-light-danger icon-btn-sm" title="Discard" x-on:click="$confirm('Discard bag {{ $b->bag_no }}?', () => $wire.discard({{ $b->id }}))"><i class="ri-delete-bin-line"></i></button>@endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="10" message="No units match." icon="ri-drop-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $bags->links() }}</div>
    </div>

    <x-modal wire:model="showForm" title="Add blood unit (external / inter-bank)">
        <div class="row">
            <x-form.select class="col-md-4" label="Group" model="form.blood_group" :options="array_combine($groups, $groups)" :placeholder="false" />
            <x-form.select class="col-md-8" label="Component" model="form.component" :options="$components" :placeholder="false" />
            <x-form.input class="col-md-4" label="Volume (ml)" model="form.volume_ml" type="number" />
            <x-form.input class="col-md-4" label="Collected" model="form.collected_at" type="date" />
            <x-form.input class="col-md-4" label="Bag no." model="form.bag_no" placeholder="auto" />
            <x-form.input class="col-12" label="Storage location" model="form.storage_location" />
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Add</button></x-slot:footer>
    </x-modal>

    <x-modal wire:model="showScreening" title="TTI screening">
        @foreach ($screening as $test => $value)
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong class="text-uppercase">{{ $test }}</strong>
                <div class="btn-group btn-group-sm">
                    @foreach (['pending' => 'secondary', 'negative' => 'success', 'reactive' => 'danger'] as $v => $c)
                        <input type="radio" class="btn-check" id="sc-{{ $test }}-{{ $v }}" value="{{ $v }}" wire:model="screening.{{ $test }}">
                        <label class="btn btn-outline-{{ $c }}" for="sc-{{ $test }}-{{ $v }}">{{ ucfirst($v) }}</label>
                    @endforeach
                </div>
            </div>
        @endforeach
        <p class="fs-12 text-muted mb-0">All negative → released to stock. Any reactive → discarded.</p>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveScreening">Save</button></x-slot:footer>
    </x-modal>
</div>
