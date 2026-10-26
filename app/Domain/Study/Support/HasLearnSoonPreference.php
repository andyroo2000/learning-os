<?php

namespace App\Domain\Study\Support;

trait HasLearnSoonPreference
{
    private bool $learnSoon = false;

    public function learnSoon(): bool
    {
        return $this->learnSoon;
    }

    public function withLearnSoon(bool $learnSoon): static
    {
        $copy = clone $this;
        $copy->learnSoon = $learnSoon;

        return $copy;
    }
}
