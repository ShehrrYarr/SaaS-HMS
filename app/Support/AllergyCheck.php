<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\Patient;
use Illuminate\Support\Collection;

/**
 * Warns when a medicine matches one of the patient's recorded allergies, by name,
 * generic name or drug family (an allergy to "Penicillin" also covers Augmentin).
 */
class AllergyCheck
{
    /** Family => names (brand or generic) that belong to it. Lower case. */
    protected const FAMILIES = [
        'penicillin' => ['penicillin', 'amoxicillin', 'amoxycillin', 'ampicillin', 'augmentin', 'co-amoxiclav', 'clavulanate', 'cloxacillin', 'flucloxacillin', 'piperacillin', 'benzylpenicillin'],
        'cephalosporin' => ['cephalosporin', 'ceftriaxone', 'cefixime', 'cefuroxime', 'cephalexin', 'cefalexin', 'cefazolin', 'cefoperazone', 'cefepime'],
        'sulfa' => ['sulfa', 'sulpha', 'sulfonamide', 'sulfamethoxazole', 'co-trimoxazole', 'cotrimoxazole', 'septran', 'bactrim', 'sulfasalazine', 'sulfadiazine'],
        'nsaid' => ['nsaid', 'ibuprofen', 'brufen', 'diclofenac', 'naproxen', 'aspirin', 'mefenamic', 'ponstan', 'ketorolac', 'piroxicam', 'celecoxib'],
        'macrolide' => ['macrolide', 'azithromycin', 'clarithromycin', 'erythromycin'],
        'quinolone' => ['quinolone', 'fluoroquinolone', 'ciprofloxacin', 'levofloxacin', 'moxifloxacin', 'ofloxacin'],
        'opioid' => ['opioid', 'morphine', 'codeine', 'tramadol', 'pethidine', 'fentanyl'],
    ];

    /**
     * Recorded allergies that this medicine may trigger.
     *
     * @return Collection<int, \App\Models\PatientAllergy>
     */
    public static function conflicts(Patient $patient, ?string $medicineName, ?Medicine $medicine = null): Collection
    {
        $drug = strtolower(trim(implode(' ', array_filter([$medicineName, $medicine?->name, $medicine?->generic_name]))));
        if ($drug === '') {
            return collect();
        }

        return $patient->allergies->filter(function ($allergy) use ($drug) {
            $allergen = strtolower(trim((string) $allergy->allergen));
            if ($allergen === '' || in_array($allergy->type, ['food', 'environmental'], true)) {
                return false;
            }
            if (str_contains($drug, $allergen)) {
                return true;
            }
            foreach (self::FAMILIES as $family => $members) {
                $allergyIsFamily = str_contains($allergen, $family) || collect($members)->contains(fn ($m) => str_contains($allergen, $m));
                if ($allergyIsFamily && collect($members)->contains(fn ($m) => str_contains($drug, $m))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** "Penicillin (severe)" style summary for warnings. */
    public static function describe(Collection $allergies): string
    {
        return $allergies->map(fn ($a) => $a->allergen.($a->severity ? ' ('.$a->severity.')' : ''))->implode(', ');
    }
}
