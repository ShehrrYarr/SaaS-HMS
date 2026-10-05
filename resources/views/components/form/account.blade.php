@props(['label' => 'Received in', 'model', 'required' => true, 'live' => false, 'hint' => null, 'id' => null, 'extra' => []])
{{-- Cash / bank picker for any money movement. "extra" adds non-ledger choices such as credit. --}}
<x-form.select :label="$label" :model="$model" :options="\App\Models\BankAccount::options() + $extra" :placeholder="false"
    :required="$required" :live="$live" :hint="$hint" :id="$id" {{ $attributes }} />
