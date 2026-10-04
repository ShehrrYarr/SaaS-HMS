<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.portal')] #[Title('My Reports')] class extends Component
{
    public function with(): array
    {
        $p = auth()->user()->patient;

        return [
            'lab' => hospital()->hasModule('laboratory') ? $p->labOrders()->with('items.test')->latest('ordered_at')->get() : collect(),
            'imaging' => hospital()->hasModule('radiology') ? $p->radiologyOrders()->with('test')->latest()->get() : collect(),
        ];
    }
}; ?>

<div>
    <x-page-header title="My Reports" subtitle="Laboratory & imaging" />
    @if ($lab->isNotEmpty() || hospital()->hasModule('laboratory'))
        <div class="card">
            <div class="card-header"><h6 class="card-title mb-0"><i class="ri-flask-line me-1"></i>Lab tests</h6></div>
            <div class="table-responsive">
                <table class="table table-hms mb-0">
                    <thead class="table-light"><tr><th>Order</th><th>Date</th><th>Tests</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($lab as $o)
                            <tr><td class="fw-semibold">{{ $o->order_no }}</td><td>{{ fmt_date($o->ordered_at) }}</td><td class="fs-13">{{ $o->items->pluck('test.name')->join(', ') }}</td>
                                <td>@if ($o->status === 'approved')<span class="badge bg-success">Ready</span>@else<span class="badge bg-warning-subtle text-warning">In progress</span>@endif</td>
                                <td class="text-end">@if ($o->status === 'approved')<a href="{{ route('portal.lab-report.pdf', $o->id) }}" target="_blank" class="btn btn-sm btn-success"><i class="ri-download-2-line"></i> Download</a>@endif</td></tr>
                        @empty
                            <x-empty-row :colspan="5" message="No lab tests." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
    @if (hospital()->hasModule('radiology'))
        <div class="card mb-0">
            <div class="card-header"><h6 class="card-title mb-0"><i class="ri-scan-2-line me-1"></i>Imaging</h6></div>
            <div class="table-responsive">
                <table class="table table-hms mb-0">
                    <thead class="table-light"><tr><th>Order</th><th>Study</th><th>Date</th><th>Status</th><th>Impression</th></tr></thead>
                    <tbody>
                        @forelse ($imaging as $o)
                            <tr><td class="fw-semibold">{{ $o->order_no }}</td><td>{{ $o->test->name }}</td><td>{{ fmt_date($o->created_at) }}</td>
                                <td>@if ($o->status === 'approved')<span class="badge bg-success">Ready</span>@else<span class="badge bg-warning-subtle text-warning">In progress</span>@endif</td>
                                <td class="fs-13">{{ $o->status === 'approved' ? $o->impression : '—' }}</td></tr>
                        @empty
                            <x-empty-row :colspan="5" message="No imaging studies." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
