{{-- Livewire-driven Bootstrap modal: <x-modal wire:model="showForm" title="..."> body <x-slot:footer>..</x-slot:footer></x-modal> --}}
@props(['title' => '', 'size' => null, 'footer' => null])
<div x-data="{ show: @entangle($attributes->wire('model')) }" x-show="show" x-cloak
    x-on:keydown.escape.window="show = false"
    class="modal hms-modal" tabindex="-1" role="dialog" style="display: none;">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable {{ $size ? 'modal-'.$size : '' }}" x-show="show" x-transition>
        <div class="modal-content" x-on:click.outside="show = false">
            <div class="modal-header">
                <h5 class="modal-title">{{ $title }}</h5>
                <button type="button" class="btn-close" x-on:click="show = false" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                {{ $slot }}
            </div>
            @if ($footer)
                <div class="modal-footer">{{ $footer }}</div>
            @endif
        </div>
    </div>
</div>
