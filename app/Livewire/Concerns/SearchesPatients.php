<?php

namespace App\Livewire\Concerns;

use App\Models\Patient;
use App\Models\Staff;
use Livewire\Attributes\Renderless;

/**
 * Remote search endpoints for <x-form.search-select search="searchPatients">.
 */
trait SearchesPatients
{
    #[Renderless]
    public function searchPatients(string $q = ''): array
    {
        return Patient::search($q)->latest()->limit(20)->get()
            ->map(fn (Patient $p) => ['value' => (string) $p->id, 'label' => "{$p->full_name} · {$p->uhid} · {$p->age_gender}".($p->phone ? " · {$p->phone}" : '')])
            ->all();
    }

    protected function patientLabel(?int $id): ?string
    {
        if (! $id) {
            return null;
        }
        $p = Patient::find($id);

        return $p ? "{$p->full_name} · {$p->uhid}" : null;
    }

    protected function doctorOptions(): array
    {
        return Staff::doctors()->active()->orderBy('name')->get()
            ->mapWithKeys(fn (Staff $d) => [$d->id => $d->display_name.($d->specialization ? ' – '.$d->specialization : '')])
            ->all();
    }
}
