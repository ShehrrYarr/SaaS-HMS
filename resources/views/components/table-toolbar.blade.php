{{-- Search box + per-page + extra filters slot for list pages using WithTable --}}
@props(['placeholder' => 'Search...'])
<div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
    <div class="form-icon" style="min-width: 240px;">
        <input type="search" class="form-control form-control-icon" placeholder="{{ $placeholder }}" wire:model.live.debounce.400ms="search">
        <i class="ri-search-2-line text-muted"></i>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        {{ $slot }}
        <select class="form-select w-auto" wire:model.live="perPage">
            @foreach ([10, 25, 50, 100] as $n)
                <option value="{{ $n }}">{{ $n }} / page</option>
            @endforeach
        </select>
        <div wire:loading class="spinner-border spinner-border-sm text-primary" role="status"></div>
    </div>
</div>
