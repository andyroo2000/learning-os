<?php

namespace App\Support\Pagination;

final class CursorPagination
{
    public const MAX_PAGE_SIZE = 50;

    private function __construct() {}

    public static function clampPageSize(int $perPage): int
    {
        return min(max($perPage, 1), self::MAX_PAGE_SIZE);
    }
}
