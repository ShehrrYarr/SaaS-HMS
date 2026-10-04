<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Attendance')] class extends Component
{
    use Toasts;

    #[Url]
    public string $date = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $view = 'daily';

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
        $this->month = $this->month ?: today()->format('Y-m');
    }

    public function mark(int $staffId, string $status): void
    {
        $this->authorize('hr.attendance');
        abort_unless(in_array($status, ['present', 'absent', 'late', 'half_day', 'leave', 'holiday']), 400);
        abort_unless(Staff::whereKey($staffId)->exists(), 404);
        $existing = Attendance::where('staff_id', $staffId)->whereDate('date', $this->date)->first();
        $checkIn = in_array($status, ['present', 'late', 'half_day']) ? ($existing?->check_in ?? ($this->date === today()->toDateString() ? now()->format('H:i') : null)) : null;
        Attendance::updateOrCreate(['staff_id' => $staffId, 'date' => $this->date], ['status' => $status, 'check_in' => $checkIn, 'marked_by' => auth()->id()]);
    }

    public function setTime(int $staffId, string $field, ?string $time): void
    {
        $this->authorize('hr.attendance');
        abort_unless(in_array($field, ['check_in', 'check_out']), 400);
        $row = Attendance::firstOrNew(['staff_id' => $staffId, 'date' => $this->date]);
        $row->status ??= 'present';
        $row->{$field} = $time ?: null;
        $row->marked_by = auth()->id();

        // Late if check-in is after rostered shift start + grace.
        if ($field === 'check_in' && $time) {
            $shift = StaffShift::with('shift')->where('staff_id', $staffId)->whereDate('date', $this->date)->first()?->shift;
            if ($shift && Carbon::parse($time)->gt(Carbon::parse($shift->start_time)->addMinutes($shift->grace_minutes))) {
                $row->status = 'late';
            }
        }
        $row->save();
    }

    public function markAllPresent(): void
    {
        $this->authorize('hr.attendance');
        $marked = Attendance::whereDate('date', $this->date)->pluck('staff_id');
        $count = 0;
        foreach (Staff::active()->whereNotIn('id', $marked)->when($this->department, fn ($q) => $q->where('department_id', $this->department))->get() as $s) {
            Attendance::create(['staff_id' => $s->id, 'date' => $this->date, 'status' => 'present', 'marked_by' => auth()->id()]);
            $count++;
        }
        $this->toast("{$count} staff marked present.");
    }

    public function with(): array
    {
        $staff = Staff::active()->with('department')->when($this->department, fn ($q) => $q->where('department_id', $this->department))->orderBy('name')->get();
        $data = ['staff' => $staff, 'departments' => Department::orderBy('name')->pluck('name', 'id')];

        if ($this->view === 'monthly') {
            $start = Carbon::parse($this->month.'-01');
            $data['days'] = collect(range(1, $start->daysInMonth))->map(fn ($d) => $start->copy()->day($d));
            $data['grid'] = Attendance::whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get()
                ->groupBy('staff_id')->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->date->day));
        } else {
            $data['records'] = Attendance::whereDate('date', $this->date)->get()->keyBy('staff_id');
            $data['shifts'] = StaffShift::with('shift')->whereDate('date', $this->date)->get()->keyBy('staff_id');
            $data['summary'] = Attendance::whereDate('date', $this->date)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        }

        return $data;
    }
}; ?>

<div>
    <x-page-header title="Attendance" :subtitle="$view === 'daily' ? fmt_date($date, 'l, d M Y') : \Carbon\Carbon::parse($month.'-01')->format('F Y')">
        <div class="btn-group btn-group-sm">
            <button class="btn {{ $view === 'daily' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('view', 'daily')">Daily</button>
            <button class="btn {{ $view === 'monthly' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('view', 'monthly')">Monthly</button>
        </div>
        @if ($view === 'daily')
            <input type="date" class="form-control form-control-sm w-auto" wire:model.live="date">
            <button class="btn btn-success btn-sm" wire:click="markAllPresent"><i class="ri-check-double-line me-1"></i>Mark rest present</button>
        @else
            <input type="month" class="form-control form-control-sm w-auto" wire:model.live="month">
        @endif
        <select class="form-select form-select-sm w-auto" wire:model.live="department"><option value="">All departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
    </x-page-header>

    @if ($view === 'daily')
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach (['present', 'late', 'half_day', 'leave', 'absent', 'holiday'] as $s)
                <span class="badge bg-{{ status_color($s) }}-subtle text-{{ status_color($s) }} p-2">{{ label($s) }}: {{ $summary[$s] ?? 0 }}</span>
            @endforeach
            <span class="badge bg-light text-body p-2">Unmarked: {{ $staff->count() - $records->count() }}</span>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hms align-middle mb-0">
                    <thead class="table-light"><tr><th>Staff</th><th>Shift</th><th>Status</th><th>Check in</th><th>Check out</th></tr></thead>
                    <tbody>
                        @foreach ($staff as $s)
                            @php $r = $records[$s->id] ?? null; $sh = $shifts[$s->id]->shift ?? null; @endphp
                            <tr wire:key="at-{{ $s->id }}">
                                <td><strong>{{ $s->display_name }}</strong><div class="fs-12 text-muted">{{ $s->employee_code }} · {{ $s->department?->name }}</div></td>
                                <td class="fs-12">{{ $sh ? $sh->name.' ('.fmt_time($sh->start_time).')' : '—' }}</td>
                                <td>
                                    <div class="btn-group btn-group-sm flex-wrap">
                                        @foreach (['present' => 'P', 'late' => 'L', 'half_day' => 'H', 'leave' => 'LV', 'absent' => 'A'] as $st => $abbr)
                                            <button class="btn {{ $r?->status === $st ? 'btn-'.status_color($st) : 'btn-outline-'.status_color($st) }}" title="{{ label($st) }}" wire:click="mark({{ $s->id }}, '{{ $st }}')">{{ $abbr }}</button>
                                        @endforeach
                                    </div>
                                </td>
                                <td><input type="time" class="form-control form-control-sm" style="width: 120px;" value="{{ $r?->check_in ? substr($r->check_in, 0, 5) : '' }}" x-on:change="$wire.setTime({{ $s->id }}, 'check_in', $event.target.value)"></td>
                                <td><input type="time" class="form-control form-control-sm" style="width: 120px;" value="{{ $r?->check_out ? substr($r->check_out, 0, 5) : '' }}" x-on:change="$wire.setTime({{ $s->id }}, 'check_out', $event.target.value)"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-bordered table-sm text-center fs-12 mb-0">
                    <thead class="table-light"><tr><th class="text-start" style="min-width: 160px;">Staff</th>@foreach ($days as $d)<th class="{{ $d->isSunday() ? 'table-secondary' : '' }}">{{ $d->day }}</th>@endforeach<th>P</th><th>A</th><th>L</th></tr></thead>
                    <tbody>
                        @foreach ($staff as $s)
                            @php $rows = $grid[$s->id] ?? collect(); @endphp
                            <tr>
                                <td class="text-start">{{ $s->display_name }}</td>
                                @foreach ($days as $d)
                                    @php $st = $rows[$d->day]->status ?? null; @endphp
                                    <td class="{{ $st ? 'bg-'.status_color($st).'-subtle text-'.status_color($st) : '' }}">{{ $st ? ['present' => 'P', 'late' => 'L', 'half_day' => 'H', 'leave' => 'LV', 'absent' => 'A', 'holiday' => 'HO'][$st] : '' }}</td>
                                @endforeach
                                <td class="fw-semibold">{{ $rows->whereIn('status', ['present', 'late'])->count() }}</td>
                                <td class="fw-semibold text-danger">{{ $rows->where('status', 'absent')->count() }}</td>
                                <td class="fw-semibold">{{ $rows->where('status', 'leave')->count() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
