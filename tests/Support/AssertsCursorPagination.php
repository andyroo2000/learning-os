<?php

namespace Tests\Support;

use App\Support\Pagination\CursorPagination;

trait AssertsCursorPagination
{
    /**
     * Requires at least two records plus the expected second-page count for the endpoint.
     */
    protected function assertCursorEndpointAcceptsCustomPageSize(string $uri, int $expectedSecondPageCount = 1): void
    {
        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => 2]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);

        $nextUrl = $response->json('links.next');

        $this->assertNotNull($response->json('meta.next_cursor'));
        $this->assertNotNull($nextUrl);
        $this->assertUrlQueryParameter($nextUrl, 'per_page', '2');

        $secondPage = $this->getJson($nextUrl);

        $secondPage
            ->assertOk()
            ->assertJsonCount($expectedSecondPageCount, 'data')
            ->assertJsonPath('meta.per_page', 2);
    }

    /**
     * Requires more than DEFAULT_PAGE_SIZE records for the endpoint to verify truncation.
     */
    protected function assertCursorEndpointUsesDefaultPageSize(string $uri): void
    {
        $response = $this->getJson($uri);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::DEFAULT_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    /**
     * Requires at least MIN_PAGE_SIZE records for the endpoint.
     */
    protected function assertCursorEndpointAcceptsMinimumPageSize(string $uri): void
    {
        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => CursorPagination::MIN_PAGE_SIZE]));

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MIN_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MIN_PAGE_SIZE);
    }

    /**
     * Requires at least MAX_PAGE_SIZE records for the endpoint.
     */
    protected function assertCursorEndpointAcceptsMaximumPageSize(string $uri): void
    {
        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => CursorPagination::MAX_PAGE_SIZE]));

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }

    protected function assertCursorEndpointRejectsPageSize(string $uri, int|string $perPage): void
    {
        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => $perPage]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
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
