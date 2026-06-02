<?php

namespace Tests\Support;

use App\Support\Pagination\CursorPagination;

trait AssertsCursorPagination
{
    /**
     * Requires at least three records for the endpoint so the first page has a next cursor.
     */
    protected function assertCursorEndpointAcceptsCustomPageSize(string $uri, ?int $expectedSecondPageCount = null): void
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
            ->assertJsonPath('meta.per_page', 2);

        if ($expectedSecondPageCount !== null) {
            $secondPage->assertJsonCount($expectedSecondPageCount, 'data');
        }
    }

    protected function assertCursorEndpointUsesDefaultPageSize(string $uri): void
    {
        $response = $this->getJson($uri);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::DEFAULT_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    protected function assertCursorEndpointAcceptsMinimumPageSize(string $uri): void
    {
        $response = $this->getJson($this->cursorPaginationUrl($uri, ['per_page' => CursorPagination::MIN_PAGE_SIZE]));

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MIN_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.per_page', CursorPagination::MIN_PAGE_SIZE);
    }

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
