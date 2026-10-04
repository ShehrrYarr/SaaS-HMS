<?php

use App\Livewire\Concerns\WithTable;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Audit Logs')] class extends Component
{
    use WithTable;

    protected string $defaultSort = 'created_at';

    protected array $sortable = ['created_at'];

    protected int $defaultPerPage = 25;

    #[Url]
    public string $event = '';

    #[Url]
    public string $user = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $from = '';

    public function with(): array
    {
        $query = AuditLog::with('user')
            ->when($this->event, fn ($q) => $q->where('event', $this->event))
            ->when($this->user, fn ($q) => $q->where('user_id', $this->user))
            ->when($this->type, fn ($q) => $q->where('auditable_type', $this->type))
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('description', 'like', "%{$this->search}%")->orWhere('ip_address', 'like', "%{$this->search}%")));

        return [
            'logs' => $this->applySort($query)->paginate($this->perPage),
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
            'types' => AuditLog::whereNotNull('auditable_type')->distinct()->pluck('auditable_type')->mapWithKeys(fn ($t) => [$t => class_basename($t)]),
            'users' => User::forCurrentHospital()->orderBy('name')->pluck('name', 'id'),
            'showHospital' => false,
        ];
    }
}; ?>

<div>
    <x-page-header title="Audit Logs" subtitle="System activity" />
    <div class="card">
        <x-table-toolbar placeholder="Description or IP...">
            <input type="date" class="form-control w-auto" wire:model.live="from">
            <select class="form-select w-auto" wire:model.live="event"><option value="">All events</option>@foreach ($events as $e)<option value="{{ $e }}">{{ label($e) }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="type"><option value="">All records</option>@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <select class="form-select w-auto" wire:model.live="user"><option value="">All users</option>@foreach ($users as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        </x-table-toolbar>
        @include('livewire.partials.audit-table', ['logs' => $logs, 'showHospital' => false])
    </div>
</div>
