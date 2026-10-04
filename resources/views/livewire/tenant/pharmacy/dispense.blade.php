<?php

use App\Livewire\Concerns\WithTable;
use App\Models\Medicine;
use App\Models\Prescription;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Dispense Prescriptions')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at'];

    #[Url]
    public string $status = 'pending';

    public function with(): array
    {
        $query = Prescription::with(['patient.currentAdmission', 'doctor', 'items.medicine'])
            ->when($this->status === 'pending', fn ($q) => $q->whereIn('status', ['issued', 'partially_dispensed']))
            ->when($this->status === 'dispensed', fn ($q) => $q->where('status', 'dispensed'))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('prescription_no', 'like', "%{$this->search}%")->orWhereHas('patient', fn ($p) => $p->search($this->search))));

        $prescriptions = $this->applySort($query)->paginate($this->perPage);
        $stock = Medicine::withStock()->whereIn('id', $prescriptions->getCollection()->flatMap->items->pluck('medicine_id')->filter())->get()->pluck('stock', 'id');

        return ['prescriptions' => $prescriptions, 'stock' => $stock];
    }
}; ?>

<div wire:poll.20s>
    <x-page-header title="Prescription Queue" subtitle="e-Prescriptions from doctors" :breadcrumbs="['Pharmacy' => route('tenant.pharmacy.pos')]" />
    <div class="card">
        <x-table-toolbar placeholder="Rx # or patient...">
            <select class="form-select w-auto" wire:model.live="status"><option value="pending">Pending</option><option value="dispensed">Dispensed</option><option value="">All</option></select>
        </x-table-toolbar>
        <div class="card-body">
            @forelse ($prescriptions as $rx)
                <div class="border rounded p-3 mb-3" wire:key="drx-{{ $rx->id }}">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div>
                            <strong>{{ $rx->prescription_no }}</strong> · <a href="{{ route('tenant.patients.show', $rx->patient) }}" wire:navigate>{{ $rx->patient->full_name }}</a>
                            <span class="text-muted">({{ $rx->patient->uhid }})</span> · {{ $rx->doctor->display_name }} · <span class="text-muted">{{ $rx->created_at->diffForHumans() }}</span>
                            @if ($rx->patient->currentAdmission)<span class="badge bg-warning-subtle text-warning">In-patient</span>@endif
                        </div>
                        <div class="d-flex gap-1">
                            <x-status :value="$rx->status" />
                            <a href="{{ route('tenant.prescriptions.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line"></i></a>
                            @if (in_array($rx->status, ['issued', 'partially_dispensed']))
                                @can('pharmacy.sell')<a href="{{ route('tenant.pharmacy.pos', ['prescription' => $rx->id]) }}" wire:navigate class="btn btn-sm btn-primary"><i class="ri-capsule-line me-1"></i>Dispense</a>@endcan
                            @endif
                        </div>
                    </div>
                    <table class="table table-sm mb-0 fs-13">
                        <thead><tr><th>Medicine</th><th>Dose / frequency</th><th>Duration</th><th class="text-end">Qty</th><th class="text-end">Dispensed</th><th class="text-end">Stock</th></tr></thead>
                        <tbody>
                            @foreach ($rx->items as $i)
                                @php $available = $i->medicine_id ? (int) ($stock[$i->medicine_id] ?? 0) : null; @endphp
                                <tr>
                                    <td>{{ $i->medicine_name }} @unless ($i->medicine_id)<span class="badge bg-light text-muted">not in formulary</span>@endunless</td>
                                    <td>{{ $i->dosage }} · {{ $i->frequency }}</td>
                                    <td>{{ $i->duration }}</td>
                                    <td class="text-end">{{ $i->quantity }}</td>
                                    <td class="text-end">{{ $i->dispensed_qty }}</td>
                                    <td class="text-end {{ $available !== null && $available < $i->pending_qty ? 'text-danger fw-semibold' : '' }}">{{ $available ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($rx->advice)<div class="fs-12 text-muted mt-2">Advice: {{ $rx->advice }}</div>@endif
                </div>
            @empty
                <p class="text-center text-muted py-4">No prescriptions waiting. 🎉</p>
            @endforelse
        </div>
        <div class="card-footer">{{ $prescriptions->links() }}</div>
    </div>
</div>
