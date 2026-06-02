<?php

namespace App\Support\Pagination;

final readonly class CursorPageSize
{
    private function __construct(
        private int $value,
    ) {}

    public static function fromMaxPageSize(): self
    {
        return new self(CursorPagination::MAX_PAGE_SIZE);
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
