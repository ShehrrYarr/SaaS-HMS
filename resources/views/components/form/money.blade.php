@props(['label' => null, 'model', 'required' => false, 'live' => false, 'hint' => null, 'id' => null])
{{-- Whole-rupee amount input. --}}
<x-form.input :label="$label" :model="$model" :required="$required" :live="$live" :hint="$hint" :id="$id"
    type="number" step="1" min="0" inputmode="numeric" :prepend="currency_symbol()" {{ $attributes }} />
