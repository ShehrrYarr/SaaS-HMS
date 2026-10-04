<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Bed;
use App\Models\Ward;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Bed Matrix')] class extends Component
{
    use Toasts;

    #[Url]
    public string $type = '';

    public function setStatus(int $bedId, string $status): void
    {
        abort_unless(auth()->user()->canAny(['beds.manage', 'ipd.vitals', 'ipd.admit']), 403);
        abort_unless(in_array($status, ['available', 'cleaning', 'maintenance', 'reserved']), 400);
        $bed = Bed::findOrFail($bedId);
        if ($bed->status === 'occupied') {
            $this->toast('Occupied beds change status through admission / discharge.', 'error');

            return;
        }
        $bed->update(['status' => $status]);
        $this->toast("Bed {$bed->bed_no} marked ".label($status).'.');
    }

    public function with(): array
    {
        $wards = Ward::where('is_active', true)->when($this->type, fn ($q) => $q->where('type', $this->type))
            ->with(['beds' => fn ($q) => $q->orderBy('bed_no')->with('currentAdmission.patient', 'currentAdmission.doctor')])
            ->orderBy('name')->get();

        $all = Bed::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return ['wards' => $wards, 'counts' => $all, 'types' => Ward::TYPES];
    }
}; ?>

<div wire:poll.15s>
    <x-page-header title="Bed Matrix" subtitle="Real-time occupancy" :breadcrumbs="['IPD' => route('tenant.ipd.index')]">
        <select class="form-select form-select-sm w-auto" wire:model.live="type">
            <option value="">All ward types</option>
            @foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
        </select>
    </x-page-header>

    <div class="d-flex flex-wrap gap-3 mb-4">
        @foreach (['available' => 'success', 'occupied' => 'danger', 'reserved' => 'info', 'cleaning' => 'warning', 'maintenance' => 'secondary'] as $s => $c)
            <span class="badge bg-{{ $c }}-subtle text-{{ $c }} fs-13 px-3 py-2"><i class="ri-checkbox-blank-circle-fill me-1"></i>{{ label($s) }}: {{ $counts[$s] ?? 0 }}</span>
        @endforeach
    </div>

    @forelse ($wards as $ward)
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="card-title mb-0">{{ $ward->name }} <small class="text-muted fw-normal">· {{ $types[$ward->type] ?? label($ward->type) }} · Floor {{ $ward->floor ?: '—' }}</small></h5>
                <span class="text-muted fs-13">{{ $ward->beds->where('status', 'occupied')->count() }}/{{ $ward->beds->count() }} occupied · {{ money($ward->charge_per_day) }}/day</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    @foreach ($ward->beds as $bed)
                        @php $adm = $bed->currentAdmission; @endphp
                        <div class="col-6 col-md-4 col-xl-2" wire:key="bed-{{ $bed->id }}">
                            <div class="bed-tile bed-{{ $bed->status }} h-100 position-relative">
                                <div class="d-flex justify-content-between align-items-start">
                                    <strong><i class="ri-hotel-bed-line me-1"></i>{{ $bed->bed_no }}</strong>
                                    @if ($bed->status !== 'occupied')
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-link p-0" data-bs-toggle="dropdown"><i class="ri-more-2-fill"></i></button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                @foreach (['available', 'reserved', 'cleaning', 'maintenance'] as $s)
                                                    @if ($s !== $bed->status)<button class="dropdown-item" wire:click="setStatus({{ $bed->id }}, '{{ $s }}')">Mark {{ label($s) }}</button>@endif
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                @if ($adm)
                                    <a href="{{ route('tenant.ipd.show', $adm) }}" wire:navigate class="d-block mt-2 text-body stretched-link">
                                        <div class="fw-semibold fs-13 text-truncate">{{ $adm->patient->full_name }}</div>
                                        <div class="fs-12 text-muted text-truncate">{{ $adm->doctor->display_name }}</div>
                                        <div class="fs-12">Day {{ $adm->lengthOfStay() }}</div>
                                    </a>
                                @else
                                    <div class="fs-12 mt-2">{{ label($bed->status) }}</div>
                                    @if (in_array($bed->status, ['available', 'reserved']))
                                        @can('ipd.admit')<a href="{{ route('tenant.ipd.admit', ['bed' => $bed->id]) }}" wire:navigate class="btn btn-sm btn-success mt-1 py-0">Admit</a>@endcan
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <div class="alert alert-info">No wards configured. @can('beds.manage')<a href="{{ route('tenant.ipd.wards') }}" wire:navigate>Add wards &amp; beds</a>@endcan</div>
    @endforelse
</div>
