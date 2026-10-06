<?php

use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Livewire\Concerns\Toasts;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Manage Hospital')] class extends Component
{
    use Toasts;

    public Hospital $hospital;

    /** Keys bound by searchable dropdowns must exist on first render, before the modal fills them. */
    public array $form = ['timezone' => null];

    public ?int $plan_id = null;

    public string $cycle = 'monthly';

    public ?string $extend_until = null;

    public string $suspend_reason = '';

    public bool $showEdit = false;

    public function mount(Hospital $hospital): void
    {
        $this->hospital = $hospital;
        $this->plan_id = $hospital->plan_id;
        $this->cycle = $hospital->billing_cycle;
        $this->extend_until = $hospital->subscription_ends_at?->toDateString();
    }

    public function edit(): void
    {
        $this->form = $this->hospital->only(['name', 'slug', 'email', 'phone', 'address', 'city', 'state', 'country', 'timezone', 'tax_label', 'tax_rate']);
        $this->resetValidation();
        $this->showEdit = true;
    }

    public function update(): void
    {
        $data = $this->validate([
            'form.name' => 'required|string|max:150',
            'form.slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('hospitals', 'slug')->ignore($this->hospital->id)],
            'form.email' => 'nullable|email',
            'form.phone' => 'nullable|string|max:30',
            'form.address' => 'nullable|string|max:255',
            'form.city' => 'nullable|string|max:100',
            'form.state' => 'nullable|string|max:100',
            'form.country' => 'nullable|string|max:100',
            'form.timezone' => 'required|timezone',
            'form.tax_label' => 'required|string|max:30',
            'form.tax_rate' => 'required|numeric|min:0|max:100',
        ])['form'];

        $this->hospital->update($data);
        $this->showEdit = false;
        $this->toast('Hospital updated.');
    }

    public function changePlan(SubscriptionService $billing): void
    {
        $this->validate(['plan_id' => 'required|exists:plans,id', 'cycle' => 'required|in:monthly,yearly']);
        $billing->changePlan($this->hospital, Plan::findOrFail($this->plan_id), $this->cycle);
        $this->hospital->refresh();
        $this->toast('Plan updated. Module access & role permissions synchronised.');
    }

    public function extend(): void
    {
        $this->validate(['extend_until' => 'required|date']);
        $this->hospital->update(['subscription_ends_at' => $this->extend_until, 'status' => $this->hospital->isSuspended() ? 'active' : $this->hospital->status]);
        $this->toast('Subscription end date updated.');
    }

    public function generateInvoice(SubscriptionService $billing): void
    {
        $invoice = $billing->generateInvoice($this->hospital);
        $this->toast("Invoice {$invoice->number} ready.");
    }

    public function suspend(SubscriptionService $billing): void
    {
        $this->validate(['suspend_reason' => 'required|string|max:200']);
        $billing->suspend($this->hospital, $this->suspend_reason);
        $this->suspend_reason = '';
        $this->toast('Hospital suspended.', 'warning');
    }

    public function activate(SubscriptionService $billing): void
    {
        $billing->activate($this->hospital);
        $this->toast('Hospital activated.');
    }

    public function delete()
    {
        $name = $this->hospital->name;
        $this->hospital->update(['status' => 'suspended', 'suspended_reason' => 'Archived']);
        $this->hospital->delete();
        session()->flash('success', "{$name} archived.");

        return $this->redirect(route('admin.hospitals.index'), navigate: true);
    }

    public function with(): array
    {
        $id = $this->hospital->id;

        return [
            'plans' => Plan::orderBy('sort_order')->pluck('name', 'id'),
            'counts' => [
                'users' => User::where('hospital_id', $id)->count(),
                'patients' => Patient::where('hospital_id', $id)->count(),
            ],
            'invoices' => SubscriptionInvoice::with('plan')->where('hospital_id', $id)->latest()->limit(10)->get(),
            'admins' => tenancy()->run($this->hospital, fn () => User::where('hospital_id', $id)->role('Hospital Admin')->get()),
            'timezones' => collect(timezone_identifiers_list())->mapWithKeys(fn ($t) => [$t => $t])->all(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="$hospital->name" subtitle="Manage" :breadcrumbs="['Hospitals' => route('admin.hospitals.index')]">
        <form method="POST" action="{{ route('admin.impersonate', $hospital) }}">
            @csrf
            <button class="btn btn-sm btn-info"><i class="ri-login-box-line me-1"></i>Open as Hospital Admin</button>
        </form>
        <button class="btn btn-sm btn-light-primary" wire:click="edit"><i class="ri-edit-line me-1"></i>Edit</button>
    </x-page-header>

    @if ($hospital->isSuspended())
        <div class="alert alert-danger d-flex justify-content-between align-items-center">
            <span><i class="ri-error-warning-line me-1"></i> Suspended: {{ $hospital->suspended_reason }}</span>
            <button class="btn btn-sm btn-success" wire:click="activate">Activate</button>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body text-center">
                    <img src="{{ $hospital->logoUrl() }}" class="hms-logo mb-3" height="48" alt="">
                    <h5 class="mb-1">{{ $hospital->name }}</h5>
                    <p class="text-muted mb-2">{{ $hospital->fullAddress() ?: 'No address' }}</p>
                    <x-status :value="$hospital->status" />
                    <hr>
                    <dl class="row text-start mb-0 fs-13">
                        <dt class="col-5">Login URL</dt><dd class="col-7"><a href="{{ route('tenant.login', ['hospital' => $hospital->slug]) }}" target="_blank">/h/{{ $hospital->slug }}</a></dd>
                        <dt class="col-5">Code</dt><dd class="col-7">{{ $hospital->code }}</dd>
                        <dt class="col-5">Email</dt><dd class="col-7">{{ $hospital->email ?: '—' }}</dd>
                        <dt class="col-5">Phone</dt><dd class="col-7">{{ $hospital->phone ?: '—' }}</dd>
                        <dt class="col-5">Timezone</dt><dd class="col-7">{{ $hospital->timezone }}</dd>
                        <dt class="col-5">Users</dt><dd class="col-7">{{ $counts['users'] }}</dd>
                        <dt class="col-5">Patients</dt><dd class="col-7">{{ number_format($counts['patients']) }}</dd>
                        <dt class="col-5">Storage</dt><dd class="col-7">{{ human_bytes($hospital->storage_used_bytes) }}</dd>
                        <dt class="col-5">Admins</dt><dd class="col-7">{{ $admins->pluck('email')->join(', ') ?: '—' }}</dd>
                    </dl>
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0 text-danger">Danger zone</h6></div>
                <div class="card-body">
                    @unless ($hospital->isSuspended())
                        <x-form.input label="Suspension reason" model="suspend_reason" placeholder="e.g. Non-payment" />
                        <button class="btn btn-sm btn-warning mb-3" x-on:click="$confirm('Suspend this hospital? Staff will be locked out.', () => $wire.suspend())">Suspend hospital</button>
                    @endunless
                    <div><button class="btn btn-sm btn-outline-danger" x-on:click="$confirm('Archive {{ addslashes($hospital->name) }}? It can be restored from the database.', () => $wire.delete())">Archive hospital</button></div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Subscription</h5></div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <x-form.select class="col-md-5 mb-0" label="Plan" model="plan_id" :options="$plans" :placeholder="false" />
                        <x-form.select class="col-md-4 mb-0" label="Billing cycle" model="cycle" :options="['monthly' => 'Monthly', 'yearly' => 'Yearly']" :placeholder="false" />
                        <div class="col-md-3"><button class="btn btn-primary w-100" wire:click="changePlan">Apply plan</button></div>
                    </div>
                    <div class="mt-3 d-flex flex-wrap gap-1">
                        @foreach (config('hms.modules') as $key => $module)
                            <span class="badge {{ $hospital->hasModule($key) ? 'bg-success-subtle text-success' : 'bg-light text-muted' }}">
                                <i class="{{ $hospital->hasModule($key) ? 'ri-check-line' : 'ri-close-line' }}"></i> {{ $module['label'] }}
                            </span>
                        @endforeach
                    </div>
                    <hr>
                    <div class="row g-3 align-items-end">
                        <x-form.input class="col-md-5 mb-0" label="Paid until" model="extend_until" type="date" />
                        <div class="col-md-3"><button class="btn btn-light-primary w-100" wire:click="extend">Update date</button></div>
                        <div class="col-md-4"><button class="btn btn-light-success w-100" wire:click="generateInvoice"><i class="ri-file-add-line me-1"></i>Generate renewal invoice</button></div>
                    </div>
                    @if ($hospital->trial_ends_at)
                        <p class="text-muted fs-12 mt-2 mb-0">Trial ends {{ fmt_date($hospital->trial_ends_at) }}</p>
                    @endif
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header d-flex justify-content-between"><h5 class="card-title mb-0">Invoices</h5><a href="{{ route('admin.invoices') }}" wire:navigate class="btn btn-sm btn-light-primary">All invoices</a></div>
                <div class="table-responsive">
                    <table class="table table-hms mb-0">
                        <thead><tr><th>Number</th><th>Plan</th><th>Period</th><th>Total</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($invoices as $inv)
                                <tr>
                                    <td>{{ $inv->number }}</td>
                                    <td>{{ $inv->plan?->name }} ({{ $inv->billing_cycle }})</td>
                                    <td>{{ fmt_date($inv->period_start) }} – {{ fmt_date($inv->period_end) }}</td>
                                    <td>{{ money($inv->total) }}</td>
                                    <td><x-status :value="$inv->status" /></td>
                                    <td class="text-end"><a title="Download PDF" aria-label="Download PDF" href="{{ route('admin.invoices.pdf', $inv->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm"><i class="ri-file-pdf-2-line"></i></a></td>
                                </tr>
                            @empty
                                <x-empty-row :colspan="6" message="No invoices yet." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <x-modal wire:model="showEdit" title="Edit hospital" size="lg">
        <div class="row">
            <x-form.input class="col-md-8" label="Name" model="form.name" required />
            <x-form.input class="col-md-4" label="Slug" model="form.slug" required />
            <x-form.input class="col-md-6" label="Email" model="form.email" />
            <x-form.input class="col-md-6" label="Phone" model="form.phone" />
            <x-form.input class="col-12" label="Address" model="form.address" />
            <x-form.input class="col-md-4" label="City" model="form.city" />
            <x-form.input class="col-md-4" label="State" model="form.state" />
            <x-form.input class="col-md-4" label="Country" model="form.country" />
            <x-form.search-select class="col-md-8" label="Timezone" model="form.timezone" :options="$timezones" />
            <x-form.input class="col-md-2" label="Tax label" model="form.tax_label" />
            <x-form.input class="col-md-2" label="Tax %" model="form.tax_rate" type="number" step="0.01" />
        </div>
        <x-slot:footer>
            <button class="btn btn-light" x-on:click="show = false">Cancel</button>
            <button class="btn btn-primary" wire:click="update">Save changes</button>
        </x-slot:footer>
    </x-modal>
</div>
