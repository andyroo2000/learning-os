<?php

namespace App\Support\Pagination;

final class CursorPagination
{
    public const MIN_PAGE_SIZE = 1;

    public const MAX_PAGE_SIZE = 50;

    // Equals the global max; endpoint request classes may lower their effective default.
    public const DEFAULT_PAGE_SIZE = self::MAX_PAGE_SIZE;

    private function __construct() {}

    public static function clampPageSize(int $perPage): int
    {
        return min(max($perPage, self::MIN_PAGE_SIZE), self::MAX_PAGE_SIZE);
    }
}
