<?php

namespace Tests\Unit\Pagination;

use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use PHPUnit\Framework\TestCase;

class CursorPageSizeTest extends TestCase
{
    public function test_default_uses_the_default_page_size(): void
    {
        $this->assertSame(
            CursorPagination::DEFAULT_PAGE_SIZE,
            CursorPageSize::fromDefaultPageSize()->value(),
        );
    }

    public function test_it_caps_the_page_size(): void
    {
        $this->assertSame(
            CursorPagination::MAX_PAGE_SIZE,
            CursorPageSize::fromPerPage(CursorPagination::MAX_PAGE_SIZE + 1)->value(),
        );
    }

    public function test_it_preserves_a_valid_page_size(): void
    {
        $this->assertSame(25, CursorPageSize::fromPerPage(25)->value());
    }

    public function test_it_preserves_the_minimum_page_size(): void
    {
        $this->assertSame(
            CursorPagination::MIN_PAGE_SIZE,
            CursorPageSize::fromPerPage(CursorPagination::MIN_PAGE_SIZE)->value(),
        );
    }

    public function test_it_uses_at_least_one_item_per_page(): void
    {
        $this->assertSame(CursorPagination::MIN_PAGE_SIZE, CursorPageSize::fromPerPage(0)->value());
    }
}
