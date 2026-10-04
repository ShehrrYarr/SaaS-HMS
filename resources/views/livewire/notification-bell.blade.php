<?php

use Livewire\Volt\Component;

/**
 * Header notification bell (database notifications), refreshed by polling.
 */
new class extends Component
{
    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
    }

    public function open(string $id)
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        if ($url = $notification->data['url'] ?? null) {
            return $this->redirect($url, navigate: true);
        }
    }

    public function with(): array
    {
        $user = auth()->user();

        return [
            'items' => $user ? $user->notifications()->latest()->limit(8)->get() : collect(),
            'unread' => $user ? $user->unreadNotifications()->count() : 0,
        ];
    }
}; ?>

<div class="dropdown features-dropdown" wire:poll.60s>
    <button type="button" class="btn icon-btn btn-text-primary rounded-circle position-relative" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
        <i class="ri-notification-2-line fs-20"></i>
        @if ($unread)
            <span class="position-absolute translate-middle badge rounded-pill p-1 min-w-20px text-bg-danger">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end p-0">
        <div class="dropdown-header d-flex align-items-center py-3">
            <h6 class="mb-0 me-auto">Notifications</h6>
            @if ($unread)
                <button type="button" class="btn btn-link btn-sm p-0" wire:click="markAllRead">Mark all read</button>
            @endif
        </div>
        <ul class="list-group list-group-flush" style="max-height: 360px; overflow-y: auto;">
            @forelse ($items as $n)
                <li class="list-group-item list-group-item-action border-start-0 border-end-0 {{ $n->read_at ? '' : 'bg-primary-subtle' }}" role="button" wire:click="open('{{ $n->id }}')">
                    <div class="d-flex gap-3">
                        <div class="avatar-item avatar avatar-title bg-{{ $n->data['color'] ?? 'primary' }}-subtle text-{{ $n->data['color'] ?? 'primary' }} flex-shrink-0">
                            <i class="{{ $n->data['icon'] ?? 'ri-notification-3-line' }}"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <h6 class="mb-1 small">{{ $n->data['title'] ?? 'Notification' }}</h6>
                            <small class="d-block text-body text-truncate">{{ $n->data['message'] ?? '' }}</small>
                            <small class="text-muted">{{ $n->created_at->diffForHumans() }}</small>
                        </div>
                    </div>
                </li>
            @empty
                <li class="list-group-item text-center text-muted py-4 border-0">You're all caught up.</li>
            @endforelse
        </ul>
    </div>
</div>
