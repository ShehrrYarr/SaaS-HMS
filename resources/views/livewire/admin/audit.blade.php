<?php

use App\Livewire\Concerns\WithTable;
use App\Models\AuditLog;
use App\Models\Hospital;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Platform Audit Log')] class extends Component
{
    use WithTable;

    #[Url]
    public string $hospital = '';

    #[Url]
    public string $event = '';

    protected array $sortable = ['created_at'];

    protected string $defaultSort = 'created_at';

    protected int $defaultPerPage = 25;

    public function with(): array
    {
        $query = AuditLog::with(['user', 'hospital'])
            ->when($this->hospital === 'platform', fn ($q) => $q->whereNull('hospital_id'))
            ->when($this->hospital && $this->hospital !== 'platform', fn ($q) => $q->where('hospital_id', $this->hospital))
            ->when($this->event, fn ($q) => $q->where('event', $this->event))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('ip_address', 'like', "%{$this->search}%")));

        return [
            'logs' => $this->applySort($query)->paginate($this->perPage),
            'hospitals' => Hospital::orderBy('name')->pluck('name', 'id'),
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Platform Audit Log" subtitle="Audit" :breadcrumbs="['Platform' => route('admin.dashboard')]" />
    <div class="card">
        <x-table-toolbar placeholder="Search description or IP...">
            <select class="form-select w-auto" wire:model.live="hospital">
                <option value="">All hospitals</option>
                <option value="platform">Platform only</option>
                @foreach ($hospitals as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            <select class="form-select w-auto" wire:model.live="event">
                <option value="">All events</option>
                @foreach ($events as $e)<option value="{{ $e }}">{{ label($e) }}</option>@endforeach
            </select>
        </x-table-toolbar>
        @include('livewire.partials.audit-table', ['logs' => $logs, 'showHospital' => true])
    </div>
</div>
