<?php

namespace Tests\Unit\Pagination;

use App\Http\Requests\Api\CursorPaginatedRequest;
use App\Support\Pagination\CursorPagination;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CursorPaginatedRequestTest extends TestCase
{
    public function test_it_exposes_cursor_page_size(): void
    {
        $request = $this->validatedRequest(['per_page' => 25]);

        $this->assertSame(25, $request->pageSize()->value());
    }

    public function test_it_uses_the_default_page_size_when_per_page_is_missing(): void
    {
        $request = $this->validatedRequest();

        $this->assertSame(CursorPagination::DEFAULT_PAGE_SIZE, $request->pageSize()->value());
    }

    public function test_it_uses_endpoint_max_when_default_page_size_exceeds_the_endpoint_cap(): void
    {
        $request = $this->validatedRequest(maxPerPage: 10);

        $this->assertSame(10, $request->pageSize()->value());
    }

    public function test_it_reads_the_validated_page_size_instead_of_raw_input(): void
    {
        $request = $this->validatedRequest(['per_page' => 25]);
        $request->merge(['per_page' => 10]);

        $this->assertSame(25, $request->pageSize()->value());
    }

    public function test_it_rejects_indexed_array_page_sizes(): void
    {
        $request = $this->validatedRequest(['per_page' => [10]]);

        $this->expectException(ValidationException::class);

        $request->pageSize();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validatedRequest(array $input = [], int $maxPerPage = CursorPagination::MAX_PAGE_SIZE): CursorPaginatedRequest
    {
        $request = new class($maxPerPage) extends CursorPaginatedRequest
        {
            public function __construct(private readonly int $endpointMaxPageSize)
            {
                parent::__construct();
            }

            protected function maxPerPage(): int
            {
                return $this->endpointMaxPageSize;
            }
        };

        $request->merge($input);
        // FormRequest::validated() runs this validator lazily, so invalid input throws instead of exercising clamping.
        $request->setValidator(Validator::make($request->all(), $request->rules()));

        return $request;
    }
}
