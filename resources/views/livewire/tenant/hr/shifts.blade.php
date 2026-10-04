<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Department;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffShift;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Shifts & Roster')] class extends Component
{
    use Toasts;

    #[Url]
    public string $week = '';

    #[Url]
    public string $department = '';

    public bool $showShift = false;

    public ?int $editingId = null;

    public array $shift = [];

    public function mount(): void
    {
        $this->week = $this->week ?: today()->startOfWeek()->toDateString();
    }

    public function moveWeek(int $delta): void
    {
        $this->week = Carbon::parse($this->week)->addWeeks($delta)->startOfWeek()->toDateString();
    }

    public function createShift(): void
    {
        $this->editingId = null;
        $this->shift = ['name' => '', 'start_time' => '08:00', 'end_time' => '16:00', 'grace_minutes' => 10, 'color' => 'primary'];
        $this->showShift = true;
    }

    public function editShift(int $id): void
    {
        $s = Shift::findOrFail($id);
        $this->editingId = $id;
        $this->shift = ['name' => $s->name, 'start_time' => substr($s->start_time, 0, 5), 'end_time' => substr($s->end_time, 0, 5), 'grace_minutes' => $s->grace_minutes, 'color' => $s->color];
        $this->showShift = true;
    }

    public function saveShift(): void
    {
        $this->authorize('hr.shifts');
        $data = $this->validate([
            'shift.name' => 'required|string|max:50',
            'shift.start_time' => 'required|date_format:H:i',
            'shift.end_time' => 'required|date_format:H:i',
            'shift.grace_minutes' => 'required|integer|min:0|max:120',
            'shift.color' => 'required|in:primary,success,info,warning,danger,secondary',
        ])['shift'];
        $this->editingId ? Shift::findOrFail($this->editingId)->update($data) : Shift::create($data);
        $this->showShift = false;
        $this->toast('Shift saved.');
    }

    public function assign(int $staffId, string $date, $shiftId): void
    {
        $this->authorize('hr.shifts');
        abort_unless(Staff::whereKey($staffId)->exists(), 404);
        if (! $shiftId) {
            StaffShift::where('staff_id', $staffId)->whereDate('date', $date)->delete();

            return;
        }
        abort_unless(Shift::whereKey($shiftId)->exists(), 404);
        StaffShift::updateOrCreate(['staff_id' => $staffId, 'date' => $date], ['shift_id' => $shiftId]);
    }

    public function copyPreviousWeek(): void
    {
        $this->authorize('hr.shifts');
        $start = Carbon::parse($this->week);
        $prev = StaffShift::whereBetween('date', [$start->copy()->subWeek()->toDateString(), $start->copy()->subDay()->toDateString()])->get();
        foreach ($prev as $row) {
            StaffShift::updateOrCreate(['staff_id' => $row->staff_id, 'date' => $row->date->copy()->addWeek()->toDateString()], ['shift_id' => $row->shift_id]);
        }
        $this->toast($prev->count().' assignments copied from last week.');
    }

    public function with(): array
    {
        $start = Carbon::parse($this->week)->startOfWeek();
        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));
        $staff = Staff::active()->when($this->department, fn ($q) => $q->where('department_id', $this->department))->orderBy('staff_type')->orderBy('name')->get();
        $roster = StaffShift::whereBetween('date', [$start->toDateString(), $start->copy()->addDays(6)->toDateString()])->get()
            ->groupBy('staff_id')->map(fn ($rows) => $rows->keyBy(fn ($r) => $r->date->toDateString()));

        return [
            'shifts' => Shift::orderBy('start_time')->get(),
            'days' => $days,
            'staff' => $staff,
            'roster' => $roster,
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Shifts & Roster" :subtitle="'Week of '.fmt_date($week)">
        <button class="btn btn-light btn-sm" wire:click="moveWeek(-1)"><i class="ri-arrow-left-s-line"></i></button>
        <button class="btn btn-light btn-sm" wire:click="$set('week', '{{ today()->startOfWeek()->toDateString() }}')">This week</button>
        <button class="btn btn-light btn-sm" wire:click="moveWeek(1)"><i class="ri-arrow-right-s-line"></i></button>
        <button class="btn btn-light-info btn-sm" wire:click="copyPreviousWeek">Copy last week</button>
        <button class="btn btn-primary btn-sm" wire:click="createShift"><i class="ri-add-line me-1"></i>Shift</button>
    </x-page-header>

    <div class="d-flex flex-wrap gap-2 mb-3">
        @foreach ($shifts as $s)
            <span class="badge bg-{{ $s->color }}-subtle text-{{ $s->color }} p-2" role="button" wire:click="editShift({{ $s->id }})">{{ $s->name }} · {{ fmt_time($s->start_time) }}–{{ fmt_time($s->end_time) }} <i class="ri-edit-line ms-1"></i></span>
        @endforeach
        <select class="form-select form-select-sm w-auto ms-auto" wire:model.live="department"><option value="">All departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hms table-bordered align-middle mb-0">
                <thead class="table-light">
                    <tr><th style="min-width: 200px;">Staff</th>@foreach ($days as $d)<th class="text-center {{ $d->isToday() ? 'table-primary' : '' }}">{{ $d->format('D') }}<div class="fs-11 fw-normal">{{ $d->format('d M') }}</div></th>@endforeach</tr>
                </thead>
                <tbody>
                    @forelse ($staff as $st)
                        <tr wire:key="ro-{{ $st->id }}">
                            <td><strong class="fs-13">{{ $st->display_name }}</strong><div class="fs-11 text-muted">{{ \App\Models\Staff::TYPES[$st->staff_type] ?? $st->staff_type }}</div></td>
                            @foreach ($days as $d)
                                @php $current = $roster[$st->id][$d->toDateString()] ?? null; $color = $current ? $shifts->firstWhere('id', $current->shift_id)?->color : null; @endphp
                                <td class="p-1 {{ $color ? 'bg-'.$color.'-subtle' : '' }}">
                                    <select class="form-select form-select-sm border-0 bg-transparent" x-on:change="$wire.assign({{ $st->id }}, '{{ $d->toDateString() }}', $event.target.value)">
                                        <option value="">Off</option>
                                        @foreach ($shifts as $s)<option value="{{ $s->id }}" @selected($current?->shift_id === $s->id)>{{ $s->name }}</option>@endforeach
                                    </select>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <x-empty-row :colspan="8" message="No active staff." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-modal wire:model="showShift" :title="$editingId ? 'Edit shift' : 'New shift'">
        <x-form.input label="Name" model="shift.name" required />
        <div class="row">
            <x-form.input class="col-md-4" label="Start" model="shift.start_time" type="time" />
            <x-form.input class="col-md-4" label="End" model="shift.end_time" type="time" />
            <x-form.input class="col-md-4" label="Grace (min)" model="shift.grace_minutes" type="number" />
        </div>
        <x-form.select label="Colour" model="shift.color" :options="['primary' => 'Blue', 'success' => 'Green', 'info' => 'Cyan', 'warning' => 'Amber', 'danger' => 'Red', 'secondary' => 'Grey']" :placeholder="false" />
        <x-slot:footer><button class="btn btn-light" x-on:click="show = false">Cancel</button><button class="btn btn-primary" wire:click="saveShift">Save</button></x-slot:footer>
    </x-modal>
</div>
