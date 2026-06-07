<?php

namespace Tests\Unit\Support;

use Tests\TestCase;
use UnexpectedValueException;

class TestCaseUrlHelperTest extends TestCase
{
    public function test_path_and_query_from_url_extracts_path_and_query(): void
    {
        $this->assertSame(
            '/api/cards?cursor=abc&per_page=2',
            $this->pathAndQueryFromUrl('https://example.test/api/cards?cursor=abc&per_page=2'),
        );
    }

    public function test_path_and_query_from_url_returns_queryless_path(): void
    {
        $this->assertSame(
            '/api/cards',
            $this->pathAndQueryFromUrl('https://example.test/api/cards'),
        );
    }

    public function test_path_and_query_from_url_rejects_urls_without_paths(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('URL has no path');

        $this->pathAndQueryFromUrl('?cursor=abc');
    }

    public function test_path_and_query_from_url_rejects_empty_urls(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('URL has no path');

        $this->pathAndQueryFromUrl('');
    }
}
