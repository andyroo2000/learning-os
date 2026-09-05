<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

class ListMediaAssetsPaginationApiTest extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsCustomPageSize('/api/media-assets');
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointUsesDefaultPageSize('/api/media-assets');
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(3)->for($user)->create();

        $this->assertCursorEndpointAcceptsMinimumPageSize('/api/media-assets');
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $this->assertCursorEndpointAcceptsMaximumPageSize('/api/media-assets');
    }

    public function test_it_rejects_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', CursorPagination::MAX_PAGE_SIZE + 1);
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', 0);
    }

    public function test_it_rejects_a_negative_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', -1);
    }

    public function test_it_rejects_invalid_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsPageSize('/api/media-assets', 'abc');
    }

    public function test_it_rejects_an_array_page_size(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsArrayPageSize('/api/media-assets');
    }

    public function test_it_rejects_a_blank_page_size_without_global_trim_middleware(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsBlankPageSizeWithoutTrimMiddleware('/api/media-assets');
    }

    public function test_it_rejects_invalid_cursor_values(): void
    {
        $this->signIn();

        $this->assertCursorEndpointRejectsMalformedCursor('/api/media-assets');
        $this->assertCursorEndpointRejectsArrayCursor('/api/media-assets');
        $this->assertCursorEndpointRejectsParameterlessCursor('/api/media-assets');
    }

    public function test_it_uses_cursor_pagination_with_a_stable_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $sharedTimestamp = now()->subDays(2);

        foreach (range(1, CursorPagination::MAX_PAGE_SIZE - 1) as $index) {
            MediaAsset::factory()->for($user)->create([
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        $lowTieMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pj',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);
        $highTieMediaAsset = MediaAsset::factory()->for($user)->create([
            'id' => '01jzk7k5g9e1k8z6w3b4n9y2pk',
            'created_at' => $sharedTimestamp,
            'updated_at' => $sharedTimestamp,
        ]);

        $firstPage = $this->getJson('/api/media-assets');

        $firstPage
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('data.'.(CursorPagination::MAX_PAGE_SIZE - 1).'.id', $highTieMediaAsset->id)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);

        $nextCursor = $firstPage->json('meta.next_cursor');

        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/media-assets?cursor={$nextCursor}");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lowTieMediaAsset->id)
            ->assertJsonPath('meta.next_cursor', null);
    }
}
