@props(['label' => null, 'model', 'options' => [], 'placeholder' => 'Select...', 'required' => false, 'live' => false, 'id' => null, 'hint' => null])
@php
    $id = $id ?? 'f_'.str_replace(['.', '-'], '_', $model);
    $directive = $live ? 'wire:model.live' : 'wire:model';
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'mb-3']) }}>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif
    <select id="{{ $id }}" {{ $directive }}="{{ $model }}" {{ $attributes->except('class')->class(['form-select', 'is-invalid' => $errors->has($model)]) }}>
        @if ($placeholder !== false)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
    </select>
    @error($model)<div class="invalid-feedback">{{ $message }}</div>@enderror
    @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
</div>
