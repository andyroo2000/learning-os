<?php

namespace Tests\Feature\Study;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Actions\ListStudyExportMediaAssetsAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportMediaAssetsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_media_assets_for_the_user_in_stable_order(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $firstExportedAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/second.jpg')
            ->create([
                'created_at' => now(),
                'mime_type' => 'image/jpeg',
            ]);
        $secondExportedAsset = MediaAsset::factory()
            ->for($user)
            ->withPublicUrl('https://cdn.example.test/uploads/first.jpg')
            ->create([
                'created_at' => now()->subDay(),
                'mime_type' => 'audio/mpeg',
            ]);

        MediaAsset::factory()->for($otherUser)->create();

        $assets = app(ListStudyExportMediaAssetsAction::class)->handle($user->id);

        $this->assertSame(
            [$firstExportedAsset->id, $secondExportedAsset->id],
            $assets->pluck('id')->all(),
        );
        $this->assertSame(
            ['image/jpeg', 'audio/mpeg'],
            $assets->pluck('mime_type')->all(),
        );
    }
}
