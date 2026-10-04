<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Prescriptions')] class extends Component
{
    public function with(): array
    {
        return ['prescriptions' => auth()->user()->patient->prescriptions()->with(['doctor', 'items'])->where('status', '!=', 'cancelled')->latest()->get()];
    }
}; ?>

<div>
    <x-page-header title="My Prescriptions" subtitle="e-Prescriptions" />
    @forelse ($prescriptions as $rx)
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><strong>{{ $rx->prescription_no }}</strong> · {{ $rx->doctor->display_name }} · <span class="text-muted">{{ fmt_date($rx->created_at) }}</span></div>
                <a href="{{ route('portal.prescription.pdf', $rx->id) }}" target="_blank" class="btn btn-sm btn-light-primary"><i class="ri-download-2-line"></i> PDF</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Medicine</th><th>Dose</th><th>When</th><th>For</th><th>Instructions</th></tr></thead>
                    <tbody>
                        @foreach ($rx->items as $i)<tr><td class="fw-semibold">{{ $i->medicine_name }}</td><td>{{ $i->dosage }}</td><td>{{ $i->frequency }}</td><td>{{ $i->duration }}</td><td>{{ $i->instructions }}</td></tr>@endforeach
                    </tbody>
                </table>
            </div>
            @if ($rx->advice || $rx->follow_up_date)
                <div class="card-footer fs-13">@if ($rx->advice)<strong>Advice:</strong> {{ $rx->advice }}@endif @if ($rx->follow_up_date)<span class="ms-2"><strong>Follow-up:</strong> {{ fmt_date($rx->follow_up_date) }}</span>@endif</div>
            @endif
        </div>
    @empty
        <div class="card"><div class="card-body text-center text-muted py-5">No prescriptions yet.</div></div>
    @endforelse
</div>
