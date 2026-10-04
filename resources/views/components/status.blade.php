@props(['value'])
@php $c = status_color($value); @endphp
<span {{ $attributes->merge(['class' => "badge bg-$c-subtle text-$c"]) }}>{{ label($value) }}</span>
