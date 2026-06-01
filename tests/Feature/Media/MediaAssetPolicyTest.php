<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class MediaAssetPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_a_user_to_view_their_own_media_asset(): void
    {
        $user = User::factory()->create();
        $mediaAsset = $this->mediaAssetFor($user);

        $response = Gate::forUser($user)->inspect('view', $mediaAsset);

        $this->assertTrue($response->allowed());
    }

    public function test_it_hides_another_users_media_asset_when_viewing(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $mediaAsset = $this->mediaAssetFor($otherUser);

        $response = Gate::forUser($user)->inspect('view', $mediaAsset);

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }
}
