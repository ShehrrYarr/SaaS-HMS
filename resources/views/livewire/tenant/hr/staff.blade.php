<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Spatie\Permission\Models\Role;

new #[Layout('layouts.app')] #[Title('Staff Directory')] class extends Component
{
    use WithFileUploads, WithTable;

    protected string $defaultSort = 'name';

    protected string $defaultDirection = 'asc';

    protected array $sortable = ['name', 'employee_code', 'joining_date'];

    #[Url]
    public string $type = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = 'active';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public $photo;

    public bool $createLogin = false;

    public string $loginRole = '';

    public string $loginPassword = '';

    protected function blank(): array
    {
        return ['name' => '', 'email' => '', 'phone' => '', 'gender' => '', 'date_of_birth' => '', 'staff_type' => 'other', 'department_id' => '', 'designation' => '',
            'qualification' => '', 'specialization' => '', 'license_no' => '', 'joining_date' => today()->toDateString(), 'employment_type' => 'full_time',
            'basic_salary' => 0, 'allowances' => 0, 'deductions' => 0, 'consultation_fee' => 0, 'follow_up_fee' => 0, 'commission_percent' => 0,
            'bank_name' => '', 'bank_account' => '', 'address' => '', 'status' => 'active'];
    }

    public function create(): void
    {
        $this->authorize('hr.staff');
        $this->editingId = null;
        $this->form = $this->blank();
        $this->photo = null;
        $this->createLogin = false;
        $this->loginRole = '';
        $this->loginPassword = '';
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('hr.staff');
        $s = Staff::findOrFail($id);
        $this->editingId = $id;
        $this->form = collect($this->blank())->keys()->mapWithKeys(fn ($k) => [$k => $s->{$k} instanceof \DateTimeInterface ? $s->{$k}->format('Y-m-d') : (string) ($s->{$k} ?? '')])->all();
        $this->photo = null;
        $this->createLogin = false;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('hr.staff');
        $data = $this->validate([
            'form.name' => 'required|string|max:120',
            'form.email' => ['nullable', 'email', 'max:150', tenant_unique('staff', 'email', $this->editingId)],
            'form.phone' => 'nullable|string|max:30',
            'form.gender' => 'nullable|in:male,female,other',
            'form.date_of_birth' => 'nullable|date|before:today',
            'form.staff_type' => 'required|in:'.implode(',', array_keys(Staff::TYPES)),
            'form.department_id' => ['nullable', tenant_exists('departments')],
            'form.designation' => 'nullable|string|max:100',
            'form.qualification' => 'nullable|string|max:150',
            'form.specialization' => 'nullable|string|max:100',
            'form.license_no' => 'nullable|string|max:60',
            'form.joining_date' => 'nullable|date',
            'form.employment_type' => 'required|in:full_time,part_time,visiting,contract',
            'form.basic_salary' => 'required|integer|min:0',
            'form.allowances' => 'required|integer|min:0',
            'form.deductions' => 'required|integer|min:0',
            'form.consultation_fee' => 'required|integer|min:0',
            'form.follow_up_fee' => 'required|integer|min:0',
            'form.commission_percent' => 'required|numeric|min:0|max:100',
            'form.bank_name' => 'nullable|string|max:100',
            'form.bank_account' => 'nullable|string|max:60',
            'form.address' => 'nullable|string|max:255',
            'form.status' => 'required|in:active,inactive',
            'photo' => 'nullable|image|max:2048',
            'loginRole' => [Rule::requiredIf($this->createLogin), 'nullable', Rule::exists('roles', 'name')->where('hospital_id', hospital()->id)],
            'loginPassword' => [Rule::requiredIf($this->createLogin), 'nullable', 'min:8'],
        ])['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        if ($this->createLogin) {
            $this->validate(['form.email' => ['required', 'email', Rule::unique('users', 'email')->where('hospital_id', hospital()->id)]]);
        }

        DB::transaction(function () use ($data) {
            $staff = $this->editingId ? tap(Staff::findOrFail($this->editingId))->update($data) : Staff::create($data + ['employee_code' => Sequence::code('employee', 'EMP', 4)]);
            if ($this->photo) {
                $staff->update(['photo_path' => $this->photo->store(hospital()->storagePath("staff/{$staff->id}"), 'local')]);
            }
            if ($this->createLogin && ! $staff->user_id) {
                $user = new User(['name' => $staff->name, 'email' => $staff->email, 'phone' => $staff->phone, 'password' => $this->loginPassword]);
                $user->hospital_id = hospital()->id;
                $user->save();
                $user->assignRole($this->loginRole);
                $staff->update(['user_id' => $user->id]);
            }
            if ($staff->user) {
                $staff->user->update(['status' => $staff->status === 'active' ? 'active' : 'inactive']);
            }
        });

        $this->showForm = false;
        $this->toast('Staff record saved.');
    }

    public function with(): array
    {
        $query = Staff::with(['department', 'user'])
            ->when($this->type, fn ($q) => $q->where('staff_type', $this->type))
            ->when($this->department, fn ($q) => $q->where('department_id', $this->department))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('employee_code', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")));

        return [
            'staff' => $this->applySort($query)->paginate($this->perPage),
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'types' => Staff::TYPES,
            'roles' => Role::where('hospital_id', hospital()->id)->where('name', '!=', 'Patient')->orderBy('name')->pluck('name', 'name'),
            'counts' => Staff::where('status', 'active')->selectRaw('staff_type, count(*) c')->groupBy('staff_type')->pluck('c', 'staff_type'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Staff Directory" subtitle="HR">
        <button class="btn btn-primary btn-sm" wire:click="create"><i class="ri-user-add-line me-1"></i>Add staff</button>
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($types as $k => $l)
            @if (($counts[$k] ?? 0) > 0)
                <button class="btn btn-sm {{ $type === $k ? 'btn-primary' : 'btn-light' }}" wire:click="$set('type', '{{ $type === $k ? '' : $k }}')">{{ $l }} <span class="badge bg-white text-dark">{{ $counts[$k] }}</span></button>
            @endif
        @endforeach
    </div>

    <div class="card">
        <x-table-toolbar placeholder="Name, code, phone, email...">
            <select class="form-select w-auto" wire:model.live="department"><option value="">All departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="">All</option></select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><x-th field="name" :sort="$sortField" :dir="$sortDirection">Staff</x-th><x-th field="employee_code" :sort="$sortField" :dir="$sortDirection">Code</x-th><th>Type / designation</th><th>Department</th><th>Contact</th><x-th field="joining_date" :sort="$sortField" :dir="$sortDirection">Joined</x-th><th class="text-end">Salary</th><th>Login</th><th></th></tr></thead>
                <tbody>
                    @forelse ($staff as $s)
                        <tr wire:key="st-{{ $s->id }}" class="{{ $s->status === 'active' ? '' : 'opacity-50' }}">
                            <td><div class="d-flex align-items-center gap-2"><x-avatar :src="$s->photoUrl()" :name="$s->name" size="sm" /><div><strong>{{ $s->display_name }}</strong><div class="fs-12 text-muted">{{ $s->qualification }}</div></div></div></td>
                            <td>{{ $s->employee_code }}</td>
                            <td>{{ $types[$s->staff_type] ?? $s->staff_type }}<div class="fs-12 text-muted">{{ $s->designation }}</div></td>
                            <td>{{ $s->department?->name ?? '—' }}</td>
                            <td class="fs-12">{{ $s->phone }}<div>{{ $s->email }}</div></td>
                            <td>{{ fmt_date($s->joining_date) }}</td>
                            <td class="text-end">{{ money($s->basic_salary + $s->allowances) }}@if ($s->staff_type === 'doctor')<div class="fs-12 text-muted">Fee {{ money($s->consultation_fee) }} · {{ (float) $s->commission_percent }}%</div>@endif</td>
                            <td>@if ($s->user)<span class="badge bg-success-subtle text-success" title="{{ $s->user->email }}">{{ $s->user->roleLabel() }}</span>@else<span class="text-muted fs-12">—</span>@endif</td>
                            <td class="text-end"><button class="btn btn-sm btn-light-primary icon-btn-sm" wire:click="edit({{ $s->id }})"><i class="ri-edit-line"></i></button></td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="9" message="No staff found." icon="ri-team-line" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $staff->links() }}</div>
    </div>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit staff' : 'Add staff'" size="xl">
        <div class="row">
            <div class="col-md-2 text-center mb-3">
                <x-avatar :src="$photo ? $photo->temporaryUrl() : null" :name="$form['name'] ?? '?'" size="xl" class="mb-2" />
                <input type="file" class="form-control form-control-sm" wire:model="photo" accept="image/*">
            </div>
            <div class="col-md-10">
                <div class="row">
                    <x-form.input class="col-md-4" label="Full name" model="form.name" required />
                    <x-form.select class="col-md-4" label="Staff type" model="form.staff_type" :options="$types" :placeholder="false" live />
                    <x-form.select class="col-md-4" label="Department" model="form.department_id" :options="$departments" />
                    <x-form.input class="col-md-4" label="Designation" model="form.designation" />
                    <x-form.input class="col-md-4" label="Email" model="form.email" type="email" />
                    <x-form.input class="col-md-4" label="Phone" model="form.phone" />
                    <x-form.select class="col-md-3" label="Gender" model="form.gender" :options="config('hms.genders')" />
                    <x-form.input class="col-md-3" label="Date of birth" model="form.date_of_birth" type="date" />
                    <x-form.input class="col-md-3" label="Joining date" model="form.joining_date" type="date" />
                    <x-form.select class="col-md-3" label="Employment" model="form.employment_type" :options="['full_time' => 'Full time', 'part_time' => 'Part time', 'visiting' => 'Visiting', 'contract' => 'Contract']" :placeholder="false" />
                </div>
            </div>
            <x-form.input class="col-md-4" label="Qualification" model="form.qualification" />
            <x-form.input class="col-md-4" label="Specialization" model="form.specialization" />
            <x-form.input class="col-md-4" label="License / registration no." model="form.license_no" />
            <div class="col-12"><h6 class="mt-2">Payroll</h6></div>
            <x-form.money class="col-md-3" label="Basic salary" model="form.basic_salary" />
            <x-form.money class="col-md-3" label="Allowances" model="form.allowances" />
            <x-form.money class="col-md-3" label="Fixed deductions" model="form.deductions" />
            <x-form.select class="col-md-3" label="Status" model="form.status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :placeholder="false" />
            @if (($form['staff_type'] ?? '') === 'doctor')
                <x-form.money class="col-md-4" label="Consultation fee" model="form.consultation_fee" />
                <x-form.money class="col-md-4" label="Follow-up fee" model="form.follow_up_fee" />
                <x-form.input class="col-md-4" label="Commission % (fee split)" model="form.commission_percent" type="number" step="0.01" />
            @endif
            <x-form.input class="col-md-4" label="Bank" model="form.bank_name" />
            <x-form.input class="col-md-4" label="Account no." model="form.bank_account" />
            <x-form.input class="col-md-4" label="Address" model="form.address" />
            @can('users.manage')
                @unless ($editingId && \App\Models\Staff::find($editingId)?->user_id)
                    <div class="col-12"><hr><x-form.switch label="Create a login account for this staff member" model="createLogin" live /></div>
                    @if ($createLogin)
                        <x-form.select class="col-md-6" label="Role" model="loginRole" :options="$roles" required />
                        <x-form.input class="col-md-6" label="Initial password" model="loginPassword" type="password" required />
                    @endif
                @endunless
            @endcan
        </div>
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="save">Save</button></x-slot:footer>
    </x-modal>
</div>
