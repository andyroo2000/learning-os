<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Actions\ShowMediaAssetAction;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $mediaAssetId = strtolower((string) Str::ulid());

        try {
            app(ShowMediaAssetAction::class)->handle($mediaAssetId);
            $this->fail('Expected missing media assets to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(MediaAsset::class, $exception->getModel());
            $this->assertSame([$mediaAssetId], $exception->getIds());
        }
    }

    public function test_it_rejects_malformed_media_asset_ids_without_echoing_the_id(): void
    {
        try {
            app(ShowMediaAssetAction::class)->handle('not-a-ulid');
            $this->fail('Expected malformed media asset IDs to be hidden as not found.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(MediaAsset::class, $exception->getModel());
            $this->assertSame([], $exception->getIds());
        }
    }

    public function test_it_rejects_malformed_media_asset_ids_without_querying_media_assets(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(ShowMediaAssetAction::class)->handle('not-a-ulid');
            $this->fail('Expected malformed media asset IDs to be hidden as not found.');
        } catch (ModelNotFoundException) {
            $queries = collect(DB::getQueryLog());

            $this->assertCount(
                0,
                $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'media_assets')),
                'Malformed media asset IDs should return not-found before querying media_assets.',
            );
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
