<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Turns Livewire field keys into readable names in validation messages when a form doesn't name
 * them itself: "form.bank_account_id" -> "bank account", "items.0.unit_price" -> "unit price",
 * "hospital.tax_rate" -> "hospital tax rate" (instead of "The hospital.tax rate field ...").
 */
class FriendlyValidator extends Validator
{
    /** Wrapper keys that carry no meaning for the person filling the form. */
    protected const CONTAINERS = [
        'form', 'item', 'items', 'pay', 'charge', 'quick', 'ward', 'batch', 'transfer', 'adjust', 'settings',
        'received', 'lines', 'params', 'data', 'state', 'cart', 'values', 'results', 'checklist',
    ];

    public function getDisplayableAttribute($attribute)
    {
        $primary = $this->getPrimaryAttribute($attribute);

        foreach (array_unique([$attribute, $primary]) as $name) {
            if ($this->getAttributeFromLocalArray($name) || $this->getAttributeFromTranslations($name)) {
                return parent::getDisplayableAttribute($attribute);
            }
        }

        $segments = array_values(array_filter(explode('.', $attribute), fn ($s) => $s !== '*' && ! ctype_digit($s)));
        if (count($segments) > 1 && in_array($segments[0], self::CONTAINERS, true)) {
            array_shift($segments);
        }

        $name = implode(' ', array_map(fn ($s) => str_replace('_', ' ', Str::snake($s)), $segments));

        return preg_replace('/ id$/', '', $name) ?: $attribute;
    }
}
