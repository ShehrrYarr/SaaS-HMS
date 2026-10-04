<?php

namespace App\Livewire\Concerns;

/**
 * Blocks actions that would break the shared public demo hospital for other visitors.
 */
trait GuardsDemo
{
    protected function demoLocked(string $what = 'This action'): bool
    {
        if (! is_demo_hospital()) {
            return false;
        }
        $this->dispatch('toast', type: 'warning', message: "{$what} is locked in the public demo (it resets every ".config('hms.demo.reset_every_hours').' hours).');

        return true;
    }
}
