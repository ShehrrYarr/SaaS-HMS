<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Prescription;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Prescriptions')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at'];

    #[Url]
    public string $status = '';

    public function cancel(int $id): void
    {
        $this->authorize('prescriptions.create');
        $rx = Prescription::findOrFail($id);
        abort_unless($rx->status === 'issued', 422);
        $rx->update(['status' => 'cancelled']);
        $this->toast('Prescription cancelled.', 'warning');
    }

    public function with(): array
    {
        $doctor = auth()->user()->staff?->staff_type === 'doctor' ? auth()->user()->staff : null;
        $query = Prescription::with(['patient', 'doctor', 'items'])
            ->when($doctor, fn ($q) => $q->where('doctor_id', $doctor->id))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('prescription_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        return ['prescriptions' => $this->applySort($query)->paginate($this->perPage), 'mine' => (bool) $doctor];
    }
}; ?>

<div>
    <x-page-header title="Prescriptions" :subtitle="$mine ? 'Written by me' : 'All e-prescriptions'" />
    <div class="card">
        <x-table-toolbar placeholder="Rx # or patient...">
            <select class="form-select w-auto" wire:model.live="status"><option value="">Any status</option>@foreach (['issued', 'partially_dispensed', 'dispensed', 'cancelled'] as $s)<option value="{{ $s }}">{{ label($s) }}</option>@endforeach</select>
        </x-table-toolbar>
        <div class="table-responsive">
            <table class="table table-hms table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Rx</th><x-th field="created_at" :sort="$sortField" :dir="$sortDirection">Date</x-th><th>Patient</th><th>Doctor</th><th>Medicines</th><th>Follow-up</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($prescriptions as $rx)
                        <tr wire:key="rx-{{ $rx->id }}">
                            <td class="fw-semibold">{{ $rx->prescription_no }}</td>
                            <td>{{ fmt_datetime($rx->created_at) }}</td>
                            <td><a href="{{ route('tenant.patients.show', $rx->patient) }}" wire:navigate>{{ $rx->patient->full_name }}</a></td>
                            <td>{{ $rx->doctor->display_name }}</td>
                            <td class="fs-13">{{ $rx->items->pluck('medicine_name')->join(', ') }}</td>
                            <td>{{ fmt_date($rx->follow_up_date) }}</td>
                            <td><x-status :value="$rx->status" /></td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('tenant.prescriptions.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light icon-btn-sm" title="Print"><i class="ri-printer-line"></i></a>
                                @if ($rx->status === 'issued')
                                    @can('prescriptions.create')<button class="btn btn-sm btn-light-danger icon-btn-sm" title="Cancel" x-on:click="$confirm('Cancel {{ $rx->prescription_no }}?', () => $wire.cancel({{ $rx->id }}))"><i class="ri-close-line"></i></button>@endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No prescriptions." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $prescriptions->links() }}</div>
    </div>
</div>
