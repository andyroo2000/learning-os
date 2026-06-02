<?php

namespace App\Support\Pagination;

final readonly class CursorPageSize
{
    private function __construct(
        private int $value,
    ) {}

    public static function fromDefaultPageSize(): self
    {
        return new self(CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public static function fromPerPage(int $perPage): self
    {
        return new self(CursorPagination::clampPageSize($perPage));
    }

    public function value(): int
    {
        return $this->value;
    }
}
