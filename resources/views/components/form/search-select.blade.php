{{--
    Searchable dropdown bound to a Livewire property.
    Static:  <x-form.search-select model="form.doctor_id" :options="$doctors" label="Doctor" />
    Remote:  <x-form.search-select model="form.patient_id" search="searchPatients" :selected-label="$patientLabel" />
             (component method `#[Renderless] searchPatients(string $q): array` returns [['value'=>1,'label'=>'...'], ...])
--}}
@props(['label' => null, 'model', 'options' => [], 'search' => null, 'placeholder' => 'Select...', 'required' => false, 'live' => false, 'selectedLabel' => null, 'id' => null])
@php
    $id = $id ?? 'f_'.str_replace(['.', '-'], '_', $model);
    $items = collect($options)->map(fn ($text, $value) => ['value' => (string) $value, 'label' => (string) $text])->values();
@endphp
{{-- Alpine reads the options once, so a changed list gets a new key and a fresh dropdown. --}}
<div {{ $attributes->only('class')->merge(['class' => 'mb-3']) }} wire:key="ss-{{ $id }}-{{ md5($items->toJson()) }}"
    x-data="{
        open: false, query: '', highlighted: 0, loading: false,
        items: @js($items),
        value: @entangle($model){{ $live ? '.live' : '' }},
        selectedLabel: @js($selectedLabel),
        remote: @js($search),
        get filtered() {
            if (this.remote) return this.items;
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.label.toLowerCase().includes(q)).slice(0, 200);
        },
        get display() {
            const found = this.items.find(i => String(i.value) === String(this.value));
            return found ? found.label : (this.value ? (this.selectedLabel || this.value) : '');
        },
        async fetch() {
            if (!this.remote) return;
            this.loading = true;
            try { this.items = await $wire.call(this.remote, this.query); } finally { this.loading = false; }
            this.highlighted = 0;
        },
        toggle() {
            this.open = !this.open;
            if (this.open) { this.$nextTick(() => this.$refs.q.focus()); this.fetch(); }
        },
        choose(item) { this.selectedLabel = item.label; this.value = item.value; this.open = false; this.query = ''; },
        clear() { this.value = null; this.selectedLabel = null; }
    }"
    x-on:click.outside="open = false">
    @if ($label)
        <label class="form-label" for="{{ $id }}">{{ $label }}@if ($required)<span class="text-danger ms-1">*</span>@endif</label>
    @endif
    <div class="hms-search-select">
        <div id="{{ $id }}" tabindex="0" role="combobox" x-on:click="toggle()" x-on:keydown.enter.prevent="toggle()"
            class="form-select d-flex align-items-center justify-content-between text-start @error($model) is-invalid @enderror">
            <span class="text-truncate" :class="{ 'text-muted': !display }" x-text="display || @js($placeholder)"></span>
            <i x-show="value" x-on:click.stop="clear()" class="ri-close-line text-muted me-3" role="button"></i>
        </div>
        <div class="hms-search-select-menu" x-show="open" x-cloak x-transition.opacity>
            <div class="p-2 border-bottom">
                <input x-ref="q" type="text" class="form-control form-control-sm" placeholder="Type to search..."
                    x-model="query" x-on:input.debounce.300ms="fetch()"
                    x-on:keydown.arrow-down.prevent="highlighted = Math.min(highlighted + 1, filtered.length - 1)"
                    x-on:keydown.arrow-up.prevent="highlighted = Math.max(highlighted - 1, 0)"
                    x-on:keydown.enter.prevent="filtered[highlighted] && choose(filtered[highlighted])"
                    x-on:keydown.escape.prevent="open = false">
            </div>
            <template x-for="(item, index) in filtered" :key="item.value">
                <div class="hms-search-select-option" :class="{ active: index === highlighted }" x-on:click="choose(item)" x-on:mouseenter="highlighted = index" x-text="item.label"></div>
            </template>
            <div x-show="loading" class="p-2 text-muted small">Searching…</div>
            <div x-show="!loading && filtered.length === 0" class="p-2 text-muted small">No results</div>
        </div>
    </div>
    @error($model)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>
