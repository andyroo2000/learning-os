<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use App\Support\Pagination\CursorPageSize;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_media_assets_for_the_user(): void
    {
        $user = User::factory()->create();
        $ownedMediaAsset = MediaAsset::factory()->for($user)->create();
        MediaAsset::factory()->for(User::factory()->create())->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle($user->id);

        $this->assertSame([$ownedMediaAsset->id], collect($mediaAssets->items())->pluck('id')->all());
    }

    public function test_it_allows_a_smaller_page_size(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(3)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(2),
        );

        $this->assertSame(2, $mediaAssets->perPage());
        $this->assertCount(2, $mediaAssets->items());
    }

    public function test_it_uses_the_max_page_size_by_default(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle($user->id);

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->items());
    }

    public function test_it_caps_page_size(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            pageSize: CursorPageSize::fromPerPage(200),
        );

        $this->assertSame(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->perPage());
        $this->assertCount(CursorPagination::MAX_PAGE_SIZE, $mediaAssets->items());
    }
}
