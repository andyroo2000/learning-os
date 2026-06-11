<?php

namespace Tests\Support;

use App\Support\Pagination\CursorPagination;
use Illuminate\Pagination\Cursor;

/**
 * Helpers for authenticated cursor-paginated API requests.
 *
 * Callers must sign in before using these assertions.
 */
trait AssertsCursorPagination
{
    /**
     * Caller must create at least 2 + $expectedSecondPageCount records for an endpoint
     * that accepts per_page=2.
     *
     * @param  int  $expectedSecondPageCount  Expected record count after following a per_page=2 cursor.
     */
    protected function assertCursorEndpointAcceptsCustomPageSize(string $uri, int $expectedSecondPageCount = 1): void
    {
        $this->assertAuthenticated();

        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => 2]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $nextUrl = $response->json('links.next');

        $this->assertNotNull($response->json('meta.next_cursor'));
        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '2');

        $secondPage = $this->getJson($this->pathAndQueryFromUrl($nextUrl));

        $secondPage
            ->assertOk()
            ->assertJsonCount($expectedSecondPageCount, 'data')
            ->assertJsonPath('meta.per_page', 2);
    }

    /**
     * Requires more than DEFAULT_PAGE_SIZE records for the endpoint to verify truncation.
     * Pass $endpointMaxPageSize for endpoints with a cap below CursorPagination::MAX_PAGE_SIZE.
     */
    protected function assertCursorEndpointUsesDefaultPageSize(string $uri, ?int $endpointMaxPageSize = null): void
    {
        $this->assertAuthenticated();

        $expectedPageSize = min(CursorPagination::DEFAULT_PAGE_SIZE, $endpointMaxPageSize ?? CursorPagination::MAX_PAGE_SIZE);

        $response = $this->getJson($uri);

        $response
            ->assertOk()
            ->assertJsonCount($expectedPageSize, 'data')
            ->assertJsonPath('meta.per_page', $expectedPageSize);
    }

    /**
     * Requires at least MIN_PAGE_SIZE records for the endpoint.
     */
    protected function assertCursorEndpointAcceptsMinimumPageSize(string $uri): void
    {
        $this->assertAuthenticated();

        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => CursorPagination::MIN_PAGE_SIZE]));

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MIN_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MIN_PAGE_SIZE);
    }

    /**
     * Requires at least MAX_PAGE_SIZE records for the endpoint.
     * Pass $endpointMaxPageSize for endpoints with a cap below CursorPagination::MAX_PAGE_SIZE.
     */
    protected function assertCursorEndpointAcceptsMaximumPageSize(string $uri, int $endpointMaxPageSize = CursorPagination::MAX_PAGE_SIZE): void
    {
        $this->assertAuthenticated();

        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => $endpointMaxPageSize]));

        $response
            ->assertOk()
            ->assertJsonCount($endpointMaxPageSize, 'data')
            ->assertJsonPath('meta.per_page', $endpointMaxPageSize);
    }

    protected function assertCursorEndpointRejectsPageSize(string $uri, int|string $perPage): void
    {
        $this->assertAuthenticated();

        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => $perPage]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    protected function assertCursorEndpointRejectsArrayPageSize(string $uri): void
    {
        $this->assertAuthenticated();

        // Build this manually so the query stays in the unindexed array form: per_page[]=10.
        $separator = str_contains($uri, '?') ? '&' : '?';
        $response = $this->getJson($uri.$separator.'per_page[]=10');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    protected function assertCursorEndpointRejectsMalformedCursor(string $uri): void
    {
        $this->assertAuthenticated();

        $this->getJson($this->cursorPaginationUrl($uri, ['cursor' => 'not-a-cursor']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    protected function assertCursorEndpointRejectsArrayCursor(string $uri): void
    {
        $this->assertAuthenticated();

        // Build this manually so the query stays in the unindexed array form: cursor[]=abc.
        $separator = str_contains($uri, '?') ? '&' : '?';

        $this->getJson($uri.$separator.'cursor[]=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    protected function assertCursorEndpointRejectsParameterlessCursor(string $uri): void
    {
        $this->assertAuthenticated();

        $cursor = (new Cursor([]))->encode();

        $this->getJson($this->cursorPaginationUrl($uri, ['cursor' => $cursor]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    /**
     * @param  array<string, int|string>  $query
     */
    private function cursorPaginationUrl(string $uri, array $query): string
    {
        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query($query);
    }
}
