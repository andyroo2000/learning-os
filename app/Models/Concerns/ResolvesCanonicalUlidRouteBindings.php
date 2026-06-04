<?php

namespace App\Models\Concerns;

use App\Support\Identifiers\CanonicalUlid;

trait ResolvesCanonicalUlidRouteBindings
{
    public function resolveRouteBinding($value, $field = null)
    {
        return parent::resolveRouteBinding($this->canonicalRouteBindingValue($value, $field), $field);
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        return parent::resolveSoftDeletableRouteBinding($this->canonicalRouteBindingValue($value, $field), $field);
    }

    private function canonicalRouteBindingValue(mixed $value, ?string $field): mixed
    {
        if (! is_string($value) || ! $this->shouldCanonicalizeRouteBindingField($field)) {
            return $value;
        }

        return CanonicalUlid::normalize($value);
    }

    private function shouldCanonicalizeRouteBindingField(?string $field): bool
    {
        return $field === null
            || $field === $this->getRouteKeyName()
            || $field === $this->getKeyName();
    }
}
