@props(['label' => null, 'model', 'type' => 'text', 'required' => false, 'live' => false, 'hint' => null, 'prepend' => null, 'append' => null, 'id' => null])
@php
    $id = $id ?? 'f_'.str_replace(['.', '-'], '_', $model);
    $directive = $live ? 'wire:model.live.debounce.400ms' : 'wire:model';
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'mb-3']) }}>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif
    @if ($prepend || $append)<div class="input-group has-validation">@endif
        @if ($prepend)<span class="input-group-text">{!! $prepend !!}</span>@endif
        <input type="{{ $type }}" id="{{ $id }}" {{ $directive }}="{{ $model }}"
            {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($model)]) }}>
        @if ($append)<span class="input-group-text">{!! $append !!}</span>@endif
        @error($model)<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($prepend || $append)</div>@endif
    @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
</div>
