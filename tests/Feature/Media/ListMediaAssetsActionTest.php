<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\ListMediaAssetsAction;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_smaller_page_size(): void
    {
        $user = User::factory()->create();

        MediaAsset::factory()->count(3)->for($user)->create();

        $mediaAssets = app(ListMediaAssetsAction::class)->handle(
            userId: $user->id,
            perPage: 2,
        );

        $this->assertSame(2, $mediaAssets->perPage());
        $this->assertCount(2, $mediaAssets->items());
    }
}
