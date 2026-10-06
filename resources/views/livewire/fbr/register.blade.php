<?php

use App\Models\AuditLog;
use App\Services\FbrRegister;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.fbr')] #[Title('Patient Visits')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->applyRange($this->from, $this->to);
    }

    public function updatedFrom(): void
    {
        $this->applyRange($this->from, $this->to);
    }

    public function updatedTo(): void
    {
        $this->applyRange($this->from, $this->to);
    }

    public function preset(string $preset): void
    {
        $today = today();
        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'week' => [$today->copy()->startOfWeek(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$today->copy()->startOfYear(), $today],
            default => [$today->copy()->startOfMonth(), $today],
        };
        $this->applyRange($from->toDateString(), $to->toDateString());
    }

    /** Every range the officer looks at is recorded in the hospital's audit log. */
    protected function applyRange(?string $from, ?string $to): void
    {
        [$this->from, $this->to] = FbrRegister::range($from, $to);
        $this->resetPage();
        AuditLog::record('fbr_viewed', null, [], ['from' => $this->from, 'to' => $this->to],
            'FBR viewed the patient visit register ('.fmt_date($this->from).' – '.fmt_date($this->to).')');
    }

    public function with(FbrRegister $register): array
    {
        $visits = $register->query($this->from, $this->to)->paginate(25);

        return [
            'visits' => $visits,
            'rows' => $register->rows($visits->items()),
            'pdfTooBig' => $visits->total() > FbrRegister::PDF_MAX_ROWS,
        ];
    }
}; ?>

<div>
    <x-page-header title="Patient Visits" subtitle="FBR register · read-only">
        <a href="{{ route('fbr.export', ['format' => 'csv', 'from' => $from, 'to' => $to]) }}" class="btn btn-light-success btn-sm"><i class="ri-file-excel-2-line me-1"></i>Excel (CSV)</a>
        @if ($pdfTooBig)
            <span class="d-inline-block" tabindex="0" title="Too many visits for one PDF ({{ number_format(\App\Services\FbrRegister::PDF_MAX_ROWS) }} max). Choose a shorter range or use the CSV.">
                <button class="btn btn-light-danger btn-sm" disabled><i class="ri-file-pdf-2-line me-1"></i>PDF</button>
            </span>
        @else
            <a href="{{ route('fbr.export', ['format' => 'pdf', 'from' => $from, 'to' => $to]) }}" class="btn btn-light-danger btn-sm"><i class="ri-file-pdf-2-line me-1"></i>PDF</a>
        @endif
    </x-page-header>

    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-end gap-3">
            <div>
                <label class="form-label fs-12 text-muted mb-1" for="fbr-from">From</label>
                <input type="date" id="fbr-from" class="form-control form-control-sm" wire:model.change="from" max="{{ $to }}">
            </div>
            <div>
                <label class="form-label fs-12 text-muted mb-1" for="fbr-to">To</label>
                <input type="date" id="fbr-to" class="form-control form-control-sm" wire:model.change="to" min="{{ $from }}">
            </div>
            <div class="d-flex flex-wrap gap-1">
                @foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This week', 'month' => 'This month', 'last_month' => 'Last month', 'year' => 'This year'] as $key => $label)
                    <button type="button" class="btn btn-light btn-sm" wire:click="preset('{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            <div class="ms-auto text-muted fs-13" wire:loading.class="opacity-50">
                <strong class="text-body">{{ number_format($visits->total()) }}</strong> {{ Str::plural('visit', $visits->total()) }}
                · {{ fmt_date($from) }} – {{ fmt_date($to) }}
            </div>
        </div>
        <div class="table-responsive" wire:loading.class="opacity-50">
            <table class="table table-hms align-middle mb-0 fs-13">
                <thead class="table-light">
                    <tr><th>Date</th><th>Visit</th><th>Patient</th><th>Contact</th><th>Department / Doctor</th><th>Diagnosis</th><th>Services</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr wire:key="v-{{ $r['type'] }}-{{ $r['ref'] }}">
                            <td class="text-nowrap">{{ fmt_date($r['at']) }}<div class="text-muted fs-12">{{ fmt_time($r['at']) }}</div></td>
                            <td class="text-nowrap">
                                <span class="badge {{ ['opd' => 'bg-primary-subtle text-primary', 'ipd' => 'bg-warning-subtle text-warning', 'lab' => 'bg-info-subtle text-info', 'radiology' => 'bg-secondary-subtle text-secondary', 'pharmacy' => 'bg-success-subtle text-success'][$r['type']] }}">{{ $r['type_label'] }}</span>
                                <div class="text-muted fs-12 mt-1">{{ $r['ref'] }}</div>
                            </td>
                            <td style="min-width: 170px">
                                <strong>{{ $r['patient']['name'] }}</strong>
                                <div class="text-muted fs-12">{{ collect([$r['patient']['uhid'], $r['patient']['age_gender']])->filter()->join(' · ') ?: 'Not registered' }}</div>
                                <div class="fs-12 text-nowrap">CNIC: {{ $r['patient']['cnic'] ?? '—' }}</div>
                            </td>
                            <td>{{ $r['patient']['phone'] ?? '—' }}<div class="text-muted fs-12">{{ $r['patient']['address'] }}</div></td>
                            <td>{{ $r['department'] ?? '—' }}<div class="text-muted fs-12">{{ $r['doctor'] }}</div></td>
                            <td style="min-width: 160px">{{ $r['diagnosis'] ?? '—' }}</td>
                            <td style="min-width: 200px">
                                @forelse ($r['services'] as $group => $items)
                                    <div><span class="text-muted">{{ $group }}:</span> {{ implode(', ', $items) }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="7" message="No patient visits in this date range." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $visits->links() }}</div>
    </div>
    <p class="text-muted fs-12 mt-2 mb-0">
        Includes OPD visits, admissions (by admission date), and walk-in lab, radiology and pharmacy customers. Tests and medicines ordered during a visit or admission are listed on that visit. Cancelled records are not shown.
    </p>
</div>
