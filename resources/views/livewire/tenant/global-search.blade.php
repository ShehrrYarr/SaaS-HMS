<?php

use App\Models\Patient;
use Livewire\Volt\Component;

/**
 * Header quick search: patients by UHID / name / phone / national ID.
 */
new class extends Component
{
    public string $q = '';

    public function with(): array
    {
        $term = trim($this->q);

        return [
            'results' => mb_strlen($term) >= 2
                ? Patient::search($term)->latest()->limit(8)->get(['id', 'uhid', 'first_name', 'last_name', 'phone', 'gender', 'date_of_birth'])
                : collect(),
        ];
    }
}; ?>

<div class="hms-global-search d-none d-sm-block" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
    <div class="form-icon">
        <input type="search" class="form-control form-control-icon" placeholder="Search patients (UHID, name, phone)  /"
            wire:model.live.debounce.300ms="q" x-on:focus="open = true" x-on:input="open = true" x-hotkey-focus="/">
        <i class="ri-search-2-line text-muted"></i>
    </div>
    <div class="dropdown-menu shadow" :class="{ show: open && $wire.q.length >= 2 }">
        @forelse ($results as $p)
            <a href="{{ route('tenant.patients.show', $p) }}" wire:navigate class="dropdown-item d-flex align-items-center gap-2 py-2" x-on:click="open = false">
                <x-avatar :name="$p->full_name" size="sm" />
                <div class="min-w-0">
                    <div class="fw-semibold text-truncate">{{ $p->full_name }} <small class="text-muted">({{ $p->age_gender }})</small></div>
                    <small class="text-muted">{{ $p->uhid }} &middot; {{ $p->phone ?: 'no phone' }}</small>
                </div>
            </a>
        @empty
            <div class="dropdown-item-text text-muted small py-3 text-center">No patients match "{{ $q }}"</div>
        @endforelse
        @can('patients.create')
            <div class="dropdown-divider"></div>
            <a href="{{ route('tenant.patients.create') }}" wire:navigate class="dropdown-item text-primary small"><i class="ri-user-add-line me-1"></i> Register new patient</a>
        @endcan
    </div>
</div>
