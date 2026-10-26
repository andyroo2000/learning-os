<?php

namespace App\Domain\Study\Support;

trait HasCardBookSource
{
    private ?array $bookSource = null;

    public function bookSource(): ?array
    {
        return $this->bookSource;
    }

    public function withBookSource(?array $source): static
    {
        $copy = clone $this;
        $copy->bookSource = CardBookSource::normalized($source);

        return $copy;
    }
}
