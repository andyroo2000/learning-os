<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\ShowMediaAssetAction;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowMediaAssetActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $shownMediaAsset = app(ShowMediaAssetAction::class)->handle($mediaAsset->id);

        $this->assertSame($mediaAsset->id, $shownMediaAsset->id);
    }

    public function test_it_normalizes_media_asset_id_before_showing(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $shownMediaAsset = app(ShowMediaAssetAction::class)->handle('  '.strtoupper($mediaAsset->id).'  ');

        $this->assertSame($mediaAsset->id, $shownMediaAsset->id);
    }

    public function test_it_rejects_missing_media_assets(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(ShowMediaAssetAction::class)->handle(strtolower((string) Str::ulid()));
    }

    public function test_it_rejects_malformed_media_asset_ids(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(ShowMediaAssetAction::class)->handle('not-a-ulid');
    }
}
