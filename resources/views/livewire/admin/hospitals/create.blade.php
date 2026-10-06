<?php

use App\Models\Hospital;
use App\Models\Plan;
use App\Services\HospitalProvisioner;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('New Hospital')] class extends Component
{
    public array $hospital = [
        'name' => '', 'slug' => '', 'code' => '', 'email' => '', 'phone' => '', 'address' => '', 'city' => '', 'state' => '',
        'country' => 'Pakistan', 'timezone' => 'Asia/Karachi', 'tax_label' => 'Tax', 'tax_rate' => 0,
    ];

    public array $admin = ['name' => '', 'email' => '', 'phone' => '', 'password' => ''];

    public ?int $plan_id = null;

    public string $cycle = 'monthly';

    public bool $trial = true;

    /** One login per default role (HospitalProvisioner::STARTER_ROLES, in order); rows left blank are skipped. */
    public bool $starterAccounts = true;

    public array $accounts = [];

    public function mount(): void
    {
        $this->plan_id = Plan::where('is_active', true)->orderBy('sort_order')->value('id');
        $this->admin['password'] = Str::password(12, symbols: false);
        foreach (array_keys(HospitalProvisioner::STARTER_ROLES) as $i => $role) {
            $this->accounts[$i] = ['name' => '', 'email' => '', 'password' => Str::password(12, symbols: false)];
        }
    }

    public function updatedHospitalName(string $value): void
    {
        if (! $this->hospital['slug']) {
            $this->hospital['slug'] = Str::slug($value);
        }
    }

    public function save(HospitalProvisioner $provisioner)
    {
        $data = $this->validate([
            'hospital.name' => 'required|string|max:150',
            'hospital.slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('hospitals', 'slug'), Rule::notIn(['admin', 'template', 'login', 'files', 'verify'])],
            'hospital.code' => ['nullable', 'alpha_num', 'max:8', Rule::unique('hospitals', 'code')],
            'hospital.email' => 'nullable|email|max:150',
            'hospital.phone' => 'nullable|string|max:30',
            'hospital.address' => 'nullable|string|max:255',
            'hospital.city' => 'nullable|string|max:100',
            'hospital.state' => 'nullable|string|max:100',
            'hospital.country' => 'nullable|string|max:100',
            'hospital.timezone' => ['required', 'timezone'],
            'hospital.tax_label' => 'required|string|max:30',
            'hospital.tax_rate' => 'required|numeric|min:0|max:100',
            'admin.name' => 'required|string|max:120',
            'admin.email' => 'required|email|max:150',
            'admin.phone' => 'nullable|string|max:30',
            'admin.password' => 'required|string|min:8',
            'plan_id' => 'required|exists:plans,id',
            'cycle' => 'required|in:monthly,yearly',
        ] + ($this->starterAccounts ? [
            'accounts' => 'array:'.implode(',', array_keys(array_keys(HospitalProvisioner::STARTER_ROLES))),
            'accounts.*.name' => 'nullable|string|max:120|required_with:accounts.*.email',
            'accounts.*.email' => ['nullable', 'email', 'max:150', 'required_with:accounts.*.name', 'distinct:ignore_case',
                fn ($attribute, $value, $fail) => strcasecmp((string) $value, $this->admin['email']) === 0 ? $fail('This is the Hospital Admin\'s email.') : null],
            'accounts.*.password' => 'nullable|string|min:8|required_with:accounts.*.email',
        ] : []), [
            'accounts.*.name.required_with' => 'Enter a name for this login.',
            'accounts.*.email.required_with' => 'Enter an email for this login, or clear the name to skip it.',
            'accounts.*.email.distinct' => 'This email is used twice.',
            'accounts.*.password.required_with' => 'Enter a password.',
        ]);

        $roles = array_keys(HospitalProvisioner::STARTER_ROLES);
        $accounts = collect($this->starterAccounts ? $data['accounts'] : [])
            ->filter(fn ($a) => filled($a['email'] ?? null))
            ->map(fn ($a, $i) => ['role' => $roles[$i], 'name' => $a['name'], 'email' => $a['email'], 'password' => $a['password']])
            ->values()->all();

        $hospitalData = array_filter($data['hospital'], fn ($v) => $v !== '' && $v !== null);
        $hospitalData['code'] = $hospitalData['code'] ?? null;
        if (! $hospitalData['code']) {
            unset($hospitalData['code']);
        }

        $hospital = $provisioner->create($hospitalData, $data['admin'], Plan::findOrFail($this->plan_id), $this->cycle, $this->trial, $accounts);

        $extra = $accounts ? ' and '.count($accounts).' role '.Str::plural('login', count($accounts)) : '';
        session()->flash('success', "{$hospital->name} created. Admin login: {$data['admin']['email']}{$extra}.");

        return $this->redirect(route('admin.hospitals.show', $hospital), navigate: true);
    }

    public function with(): array
    {
        return [
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'timezones' => collect(timezone_identifiers_list())->mapWithKeys(fn ($t) => [$t => $t])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header title="New Hospital" subtitle="Onboard tenant" :breadcrumbs="['Hospitals' => route('admin.hospitals.index')]" />

    <form wire:submit="save">
        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Hospital details</h5></div>
                    <div class="card-body row">
                        <x-form.input class="col-md-6" label="Hospital name" model="hospital.name" required live />
                        <x-form.input class="col-md-3" label="URL slug" model="hospital.slug" required hint="/h/{slug}" />
                        <x-form.input class="col-md-3" label="Short code" model="hospital.code" placeholder="auto" hint="Used as UHID prefix" />
                        <x-form.input class="col-md-6" label="Email" model="hospital.email" type="email" />
                        <x-form.input class="col-md-6" label="Phone" model="hospital.phone" />
                        <x-form.input class="col-md-12" label="Address" model="hospital.address" />
                        <x-form.input class="col-md-4" label="City" model="hospital.city" />
                        <x-form.input class="col-md-4" label="State / Province" model="hospital.state" />
                        <x-form.input class="col-md-4" label="Country" model="hospital.country" />
                        <x-form.search-select class="col-md-8" label="Timezone" model="hospital.timezone" :options="$timezones" required />
                        <x-form.input class="col-md-2" label="Tax label" model="hospital.tax_label" />
                        <x-form.input class="col-md-2" label="Tax %" model="hospital.tax_rate" type="number" step="0.01" />
                    </div>
                </div>
                <div class="card mb-0">
                    <div class="card-header"><h5 class="card-title mb-0">Hospital Admin account</h5></div>
                    <div class="card-body row">
                        <x-form.input class="col-md-6" label="Full name" model="admin.name" required />
                        <x-form.input class="col-md-6" label="Email (login)" model="admin.email" type="email" required />
                        <x-form.input class="col-md-6" label="Phone" model="admin.phone" />
                        <x-form.input class="col-md-6" label="Initial password" model="admin.password" required hint="Share securely; the admin should change it after first login." />
                    </div>
                </div>
                <div class="card mb-0 mt-4">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="card-title mb-0">Starter accounts</h5>
                        <x-form.switch class="mb-0" label="Create a login for each role" model="starterAccounts" live />
                    </div>
                    @if ($starterAccounts)
                        <div class="card-body">
                            <p class="text-muted fs-13">One login per default role, like the demo hospital. Staff roles also get an HR staff record (the doctor in General Medicine). Leave a row blank to skip that role.</p>
                            @foreach (array_keys(\App\Services\HospitalProvisioner::STARTER_ROLES) as $i => $role)
                                <div class="row g-2 align-items-start {{ $loop->last ? '' : 'border-bottom mb-3' }}" wire:key="starter-{{ $i }}">
                                    <div class="col-md-3 pt-md-2 mb-2 mb-md-0 fw-semibold">{{ $role }}</div>
                                    <x-form.input class="col-md-3" model="accounts.{{ $i }}.name" placeholder="Full name" aria-label="{{ $role }} full name" />
                                    <x-form.input class="col-md-3" model="accounts.{{ $i }}.email" type="email" placeholder="Email (login)" aria-label="{{ $role }} email" />
                                    <x-form.input class="col-md-3" model="accounts.{{ $i }}.password" placeholder="Password" aria-label="{{ $role }} password" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header"><h5 class="card-title mb-0">Subscription</h5></div>
                    <div class="card-body">
                        @foreach ($plans as $plan)
                            <label class="card border mb-3 p-3 {{ $plan_id == $plan->id ? 'border-primary bg-primary-subtle' : '' }}" role="button">
                                <div class="d-flex align-items-start gap-2">
                                    <input type="radio" class="form-check-input mt-1" value="{{ $plan->id }}" wire:model.live="plan_id">
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between"><strong>{{ $plan->name }}</strong><span>{{ money($plan->price_monthly) }}/mo</span></div>
                                        <small class="text-muted d-block">{{ $plan->description }}</small>
                                        <small class="text-muted">{{ count($plan->modules ?? []) }} modules &middot; {{ $plan->trial_days }}-day trial</small>
                                    </div>
                                </div>
                            </label>
                        @endforeach
                        @error('plan_id')<div class="text-danger small">{{ $message }}</div>@enderror
                        <x-form.select label="Billing cycle" model="cycle" :options="['monthly' => 'Monthly', 'yearly' => 'Yearly']" :placeholder="false" />
                        <x-form.switch label="Start with free trial" model="trial" />
                    </div>
                </div>
                <button class="btn btn-primary w-100" wire:loading.attr="disabled"><i class="ri-hospital-line me-1"></i> Create hospital</button>
            </div>
        </div>
    </form>
</div>
