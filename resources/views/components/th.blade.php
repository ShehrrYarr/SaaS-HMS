{{-- Sortable header: <x-th field="name" :sort="$sortField" :dir="$sortDirection">Name</x-th> --}}
@props(['field' => null, 'sort' => null, 'dir' => 'asc'])
@if ($field)
    <th {{ $attributes->merge(['class' => 'sortable']) }} wire:click="sortBy('{{ $field }}')">
        {{ $slot }}
        @if ($sort === $field)
            <i class="ri-arrow-{{ $dir === 'asc' ? 'up' : 'down' }}-s-fill"></i>
        @else
            <i class="ri-expand-up-down-line text-muted opacity-50"></i>
        @endif
    </th>
@else
    <th {{ $attributes }}>{{ $slot }}</th>
@endif
