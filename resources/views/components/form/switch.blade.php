@props(['label', 'model', 'live' => false, 'id' => null])
@php $id = $id ?? 'f_'.str_replace(['.', '-'], '_', $model); @endphp
<div {{ $attributes->only('class')->merge(['class' => 'form-check form-switch mb-3']) }}>
    <input class="form-check-input" type="checkbox" role="switch" id="{{ $id }}" {{ $live ? 'wire:model.live' : 'wire:model' }}="{{ $model }}">
    <label class="form-check-label" for="{{ $id }}">{{ $label }}</label>
</div>
