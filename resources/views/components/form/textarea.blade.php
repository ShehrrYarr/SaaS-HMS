@props(['label' => null, 'model', 'rows' => 3, 'required' => false, 'id' => null])
@php $id = $id ?? 'f_'.str_replace(['.', '-'], '_', $model); @endphp
<div {{ $attributes->only('class')->merge(['class' => 'mb-3']) }}>
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif
    <textarea id="{{ $id }}" rows="{{ $rows }}" wire:model="{{ $model }}" {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($model)]) }}></textarea>
    @error($model)<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
