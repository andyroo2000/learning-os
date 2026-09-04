<?php

namespace App\Support\Audio;

final class AudioStreamRangeParser
{
    /** @var array<int, string> */
    private array $matches = [];

    public function __construct(
        private readonly string $header,
        private readonly int $size,
    ) {}

    /** @return null|array{start: int, end: int} */
    public function parse(): ?array
    {
        if (! $this->hasValidParts()) {
            return null;
        }

        if ($this->matches[1] === '') {
            return $this->suffixRange();
        }

        return $this->boundedRange();
    }

    private function hasValidParts(): bool
    {
        return $this->size >= 1
            && strlen($this->header) <= 100
            && preg_match('/^bytes=(\d*)-(\d*)$/', $this->header, $this->matches) === 1
            && ! ($this->matches[1] === '' && $this->matches[2] === '');
    }

    /** @return null|array{start: int, end: int} */
    private function suffixRange(): ?array
    {
        $suffix = (int) $this->matches[2];
        if ($suffix < 1) {
            return null;
        }

        return ['start' => max(0, $this->size - $suffix), 'end' => $this->size - 1];
    }

    /** @return null|array{start: int, end: int} */
    private function boundedRange(): ?array
    {
        $start = (int) $this->matches[1];
        $end = $this->matches[2] === '' ? $this->size - 1 : min((int) $this->matches[2], $this->size - 1);
        if ($start >= $this->size || $end < $start) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }
}
