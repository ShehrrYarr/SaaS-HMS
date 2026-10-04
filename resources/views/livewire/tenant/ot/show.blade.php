<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Staff;
use App\Models\Surgery;
use App\Services\BillingService;
use App\Services\IpdService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Surgery')] class extends Component
{
    use Toasts;

    public Surgery $surgery;

    public array $pre = [];

    public array $post = [];

    public string $operative_notes = '';

    public string $post_op_notes = '';

    public ?string $memberId = null;

    public string $memberRole = 'assistant_surgeon';

    public function mount(Surgery $surgery): void
    {
        $this->surgery = $surgery;
        $this->pre = $surgery->pre_op_checklist ?? [];
        $this->post = $surgery->post_op_checklist ?? [];
        $this->operative_notes = (string) $surgery->operative_notes;
        $this->post_op_notes = (string) $surgery->post_op_notes;
    }

    public function saveChecklists(): void
    {
        $this->authorize('ot.manage');
        $this->surgery->update([
            'pre_op_checklist' => array_intersect_key($this->pre, Surgery::PRE_OP_CHECKLIST),
            'post_op_checklist' => array_intersect_key($this->post, Surgery::POST_OP_CHECKLIST),
            'operative_notes' => $this->operative_notes ?: null,
            'post_op_notes' => $this->post_op_notes ?: null,
        ]);
        $this->toast('Saved.');
    }

    public function advance(string $status, IpdService $ipd, BillingService $billing): void
    {
        $this->authorize('ot.manage');
        $flow = ['scheduled' => 'pre_op', 'pre_op' => 'in_progress', 'in_progress' => 'post_op', 'post_op' => 'completed'];
        abort_unless(($flow[$this->surgery->status] ?? null) === $status || $status === 'cancelled', 422);

        if ($status === 'in_progress' && count(array_filter($this->pre)) < count(Surgery::PRE_OP_CHECKLIST)) {
            $this->toast('Complete the pre-op checklist (WHO sign-in) before starting.', 'error');

            return;
        }
        $this->saveChecklists();

        $attrs = ['status' => $status];
        if ($status === 'in_progress') {
            $attrs['actual_start'] = now();
            $this->surgery->room->update(['status' => 'in_use']);
        }
        if ($status === 'post_op') {
            $attrs['actual_end'] = now();
            $this->surgery->room->update(['status' => 'cleaning']);
        }
        $this->surgery->update($attrs);

        if ($status === 'completed' && (float) $this->surgery->charges > 0) {
            $admission = $this->surgery->admission;
            if ($admission && $admission->status === 'admitted') {
                $ipd->addCharge($admission, ['category' => 'ot', 'description' => 'Surgery: '.$this->surgery->procedure_name, 'unit_price' => $this->surgery->charges, 'source' => $this->surgery, 'doctor_id' => $this->surgery->surgeon_id]);
            } elseif (hospital()->hasModule('billing')) {
                $billing->createInvoice($this->surgery->patient, [[
                    'service_type' => 'ot', 'description' => 'Surgery: '.$this->surgery->procedure_name, 'unit_price' => (float) $this->surgery->charges,
                    'tax_percent' => $billing->defaultTaxPercent(), 'source' => $this->surgery, 'doctor_id' => $this->surgery->surgeon_id,
                ]]);
            }
        }
        if ($status === 'cancelled' && $this->surgery->room->status === 'in_use') {
            $this->surgery->room->update(['status' => 'available']);
        }
        $this->toast('Surgery status: '.label($status));
    }

    public function addMember(): void
    {
        $this->authorize('ot.manage');
        $this->validate(['memberId' => ['required', tenant_exists('staff')], 'memberRole' => 'required|in:'.implode(',', array_keys(Surgery::TEAM_ROLES))]);
        $this->surgery->team()->create(['staff_id' => $this->memberId, 'role' => $this->memberRole]);
        $this->memberId = null;
    }

    public function removeMember(int $id): void
    {
        $this->authorize('ot.manage');
        $this->surgery->team()->findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            's' => $this->surgery->load(['patient.allergies', 'room', 'surgeon', 'team.staff', 'admission.bed.ward', 'creator']),
            'staffOptions' => Staff::active()->orderBy('name')->get()->mapWithKeys(fn ($x) => [$x->id => $x->display_name])->all(),
            'next' => ['scheduled' => 'pre_op', 'pre_op' => 'in_progress', 'in_progress' => 'post_op', 'post_op' => 'completed'][$this->surgery->status] ?? null,
        ];
    }
}; ?>

<div>
    <x-page-header :title="$s->procedure_name" :subtitle="$s->surgery_no" :breadcrumbs="['OT Schedule' => route('tenant.ot.index')]">
        <x-status :value="$s->status" />
        @can('ot.manage')
            @if ($next)
                <button class="btn btn-sm btn-primary" x-on:click="$confirm('Move to {{ label($next) }}?', () => $wire.advance('{{ $next }}'), { color: 'primary', confirmText: 'Continue' })"><i class="ri-arrow-right-line me-1"></i>{{ label($next) }}</button>
            @endif
            @if (! in_array($s->status, ['completed', 'cancelled', 'in_progress']))
                <button class="btn btn-sm btn-light-danger" x-on:click="$confirm('Cancel this surgery?', () => $wire.advance('cancelled'))">Cancel</button>
            @endif
        @endcan
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card">
                <div class="card-body fs-13">
                    <h6><a href="{{ route('tenant.patients.show', $s->patient) }}" wire:navigate>{{ $s->patient->full_name }}</a></h6>
                    <p class="text-muted">{{ $s->patient->uhid }} · {{ $s->patient->age_gender }} · {{ $s->patient->blood_group }}</p>
                    @if ($s->patient->allergies->isNotEmpty())<div class="alert alert-danger py-1 px-2">Allergies: {{ $s->patient->allergies->pluck('allergen')->join(', ') }}</div>@endif
                    <dl class="row mb-0">
                        <dt class="col-5">Room</dt><dd class="col-7">{{ $s->room->name }}</dd>
                        <dt class="col-5">Scheduled</dt><dd class="col-7">{{ fmt_datetime($s->scheduled_start) }} – {{ $s->scheduled_end->format('H:i') }}</dd>
                        <dt class="col-5">Actual</dt><dd class="col-7">{{ $s->actual_start ? $s->actual_start->format('H:i') : '—' }} – {{ $s->actual_end ? $s->actual_end->format('H:i') : '—' }}</dd>
                        <dt class="col-5">Type</dt><dd class="col-7">{{ label($s->surgery_type) }}</dd>
                        <dt class="col-5">Anesthesia</dt><dd class="col-7">{{ label($s->anesthesia_type) }}</dd>
                        <dt class="col-5">Admission</dt><dd class="col-7">@if ($s->admission)<a href="{{ route('tenant.ipd.show', $s->admission) }}" wire:navigate>{{ $s->admission->admission_no }}</a>@else Day case @endif</dd>
                        <dt class="col-5">Charges</dt><dd class="col-7">{{ money($s->charges) }}</dd>
                    </dl>
                </div>
            </div>
            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Surgical team</h6></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><strong>{{ $s->surgeon->display_name }}</strong> <span class="text-muted">· Primary surgeon</span></li>
                    @foreach ($s->team as $m)
                        <li class="list-group-item d-flex justify-content-between">{{ $m->staff->display_name }} <span class="text-muted">{{ \App\Models\Surgery::TEAM_ROLES[$m->role] ?? $m->role }} @can('ot.manage')<i class="ri-close-line text-danger ms-1" role="button" wire:click="removeMember({{ $m->id }})"></i>@endcan</span></li>
                    @endforeach
                </ul>
                @can('ot.manage')
                    <div class="card-body pt-2">
                        <div class="row g-2">
                            <div class="col-12"><x-form.search-select class="mb-0" model="memberId" :options="$staffOptions" placeholder="Add member" /></div>
                            <div class="col-8"><select class="form-select form-select-sm" wire:model="memberRole">@foreach (\App\Models\Surgery::TEAM_ROLES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            <div class="col-4"><button class="btn btn-sm btn-light-primary w-100" wire:click="addMember">Add</button></div>
                        </div>
                    </div>
                @endcan
            </div>
        </div>
        <div class="col-xl-8">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h6 class="card-title mb-0"><i class="ri-checkbox-multiple-line me-1"></i>Pre-op checklist (sign-in)</h6></div>
                        <div class="card-body">
                            @foreach (\App\Models\Surgery::PRE_OP_CHECKLIST as $k => $l)
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pre-{{ $k }}" wire:model="pre.{{ $k }}" @disabled(! auth()->user()->can('ot.manage'))><label class="form-check-label" for="pre-{{ $k }}">{{ $l }}</label></div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100 mb-0">
                        <div class="card-header"><h6 class="card-title mb-0"><i class="ri-checkbox-multiple-line me-1"></i>Post-op checklist (sign-out)</h6></div>
                        <div class="card-body">
                            @foreach (\App\Models\Surgery::POST_OP_CHECKLIST as $k => $l)
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="post-{{ $k }}" wire:model="post.{{ $k }}" @disabled(! auth()->user()->can('ot.manage'))><label class="form-check-label" for="post-{{ $k }}">{{ $l }}</label></div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card mb-0">
                        <div class="card-body">
                            @if ($s->pre_op_notes)<p class="fs-13"><strong>Pre-op notes:</strong> {{ $s->pre_op_notes }}</p>@endif
                            <x-form.textarea label="Operative notes (findings, procedure, implants, blood loss)" model="operative_notes" rows="5" />
                            <x-form.textarea label="Post-op orders / notes" model="post_op_notes" rows="3" />
                            @can('ot.manage')<button class="btn btn-primary" wire:click="saveChecklists">Save</button>@endcan
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
