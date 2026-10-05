<?php

namespace App\Support\Livewire;

use Illuminate\Validation\ValidationException;
use Livewire\ComponentHook;
use Livewire\Drawer\Utils;

/**
 * Services report problems under their own field names ("bed_id", "cart"). Livewire only keeps
 * errors named after a component property, so on a form bound to "form.bed_id" such a message
 * was silently dropped and the button seemed to do nothing. Attach it to the matching form
 * field when there is one, otherwise show it as an error toast.
 */
class SurfaceServiceErrors extends ComponentHook
{
    public function exception($e, $stopPropagation)
    {
        if (! $e instanceof ValidationException) {
            return;
        }

        // Livewire copies this bag onto the component, whichever hook runs first.
        $bag = $e->validator->errors();
        $unplaced = [];
        foreach ($e->errors() as $key => $messages) {
            if (Utils::hasProperty($this->component, $key)) {
                continue;
            }
            $field = $this->formFieldFor($key);
            foreach ($messages as $message) {
                $field ? $bag->add($field, $message) : $unplaced[] = $message;
            }
        }

        if ($unplaced) {
            $this->component->dispatch('toast', type: 'error', message: implode(' ', array_unique($unplaced)));
        }
    }

    /** "bed_id" → "form.bed_id" when a public array property holds that key. */
    protected function formFieldFor(string $key): ?string
    {
        foreach ($this->component->all() as $property => $value) {
            if (is_array($value) && array_key_exists($key, $value)) {
                return $property.'.'.$key;
            }
        }

        return null;
    }
}
