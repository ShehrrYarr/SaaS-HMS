<?php

use App\Models\OpdVisit;
use App\Models\Staff;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/** Public waiting-room token board. Protected by the hospital's display key (?key=...). */
new #[Layout('layouts.display')] #[Title('Token Display')] class extends Component
{
    #[Locked]
    public string $key = '';

    public function mount(): void
    {
        $this->key = (string) request('key');
        abort_unless($this->key !== '' && hash_equals((string) hospital()->setting('queue_display_key'), $this->key), 403, 'Invalid display key.');
    }

    public function with(): array
    {
        abort_unless(hash_equals((string) hospital()->setting('queue_display_key'), $this->key), 403);

        $visits = OpdVisit::with('patient:id,first_name,last_name')->whereDate('visit_date', today())
            ->whereIn('status', ['waiting', 'in_consultation'])->orderBy('token_no')->get()->groupBy('doctor_id');

        return [
            'doctors' => Staff::doctors()->active()->with(['schedules' => fn ($q) => $q->where('day_of_week', today()->dayOfWeek)])->whereIn('id', $visits->keys())->orderBy('name')->get(),
            'visits' => $visits,
        ];
    }
}; ?>

<div wire:poll.5s class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center gap-3">
            <img src="{{ hospital()->logoUrl() }}" height="48" alt="" class="hms-logo">
            <h2 class="mb-0 fw-bold">{{ hospital()->name }} · OPD</h2>
        </div>
        <div class="text-end"><div class="display-6 fw-bold">{{ now()->format('h:i A') }}</div><div class="text-muted">{{ now()->format('l, d M Y') }}</div></div>
    </div>
    <div class="row g-4">
        @forelse ($doctors as $doctor)
            @php $list = $visits->get($doctor->id, collect()); $current = $list->firstWhere('status', 'in_consultation'); $next = $list->where('status', 'waiting')->take(4); @endphp
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0 text-white">{{ $doctor->display_name }}</h4>
                        <small>{{ $doctor->specialization }} {{ $doctor->schedules->first()?->room ? '· Room '.$doctor->schedules->first()->room : '' }}</small>
                    </div>
                    <div class="card-body text-center">
                        <div class="text-muted text-uppercase">Now serving</div>
                        <div class="token-display text-primary" style="font-size: 6rem;">{{ $current ? $current->token_no : '—' }}</div>
                        <hr>
                        <div class="text-muted text-uppercase mb-2">Next</div>
                        <div class="d-flex justify-content-center gap-3 fs-2 fw-semibold">
                            @forelse ($next as $v)<span class="badge bg-light text-body">{{ $v->token_no }}</span>@empty<span class="text-muted fs-5">—</span>@endforelse
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12 text-center text-muted py-5 fs-3">No patients in queue right now.</div>
        @endforelse
    </div>
</div>
