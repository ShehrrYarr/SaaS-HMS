@props(['src' => null, 'name' => '', 'size' => null])
@php
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->join('');
    $sizeClass = $size ? 'avatar-'.$size : '';
    $palette = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
    $color = $palette[crc32($name) % count($palette)];
@endphp
@if ($src)
    <span {{ $attributes->merge(['class' => "avatar-item avatar overflow-hidden $sizeClass"]) }}>
        <img class="img-fluid" src="{{ $src }}" alt="{{ $name }}">
    </span>
@else
    <span {{ $attributes->merge(['class' => "avatar-item avatar avatar-title bg-$color-subtle text-$color fw-semibold $sizeClass"]) }}>{{ strtoupper($initials) ?: '?' }}</span>
@endif
