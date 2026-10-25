<?php

namespace App\Models\Concerns;

/**
 * For a model that keeps secrets the admin can type but never read back: what may be said about a saved one, without ever showing it.
 */
trait HasSecretHints
{
    /**
     * That a secret exists and, for a long enough one, its last 4 characters ("ends in ••••1234"). Null when nothing is saved. Never the value.
     */
    public function secretHint(string $field): ?string
    {
        $value = (string) $this->getAttribute($field);

        if ($value === '') {
            return null;
        }

        return strlen($value) >= 12 ? 'ends in ••••'.substr($value, -4) : 'value hidden';
    }
}
