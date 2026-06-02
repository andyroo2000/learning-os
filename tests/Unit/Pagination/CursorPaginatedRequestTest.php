<?php

namespace Tests\Unit\Pagination;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Support\Pagination\CursorPagination;
use Tests\TestCase;

class CursorPaginatedRequestTest extends TestCase
{
    public function test_it_exposes_cursor_page_size(): void
    {
        $request = new class extends CursorPaginatedRequest {};
        $request->merge(['per_page' => 25]);

        $this->assertSame(25, $request->pageSize()->value());
    }

    public function test_it_uses_the_default_page_size_when_per_page_is_missing(): void
    {
        $request = new class extends CursorPaginatedRequest {};

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $request->pageSize()->value());
    }

    public function test_it_clamps_cursor_page_size(): void
    {
        $request = new class extends CursorPaginatedRequest {};
        $request->merge(['per_page' => 999]);

        // HTTP validation rejects out-of-range values, but CursorPageSize clamps
        // defensively for manually constructed requests that bypass validation.
        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $request->pageSize()->value());
    }
}
