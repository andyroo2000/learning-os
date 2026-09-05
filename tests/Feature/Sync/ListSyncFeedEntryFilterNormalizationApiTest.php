<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntryFilterNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_trims_the_resource_type_filter(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'resource_type' => 'card',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?resource_type=%20card%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('data.0.checkpoint', $card->checkpoint);
    }

    public function test_it_trims_the_domain_filter(): void
    {
        $user = $this->signIn();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?domain=%20flashcards%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('data.0.checkpoint', $flashcards->checkpoint);
    }

    public function test_it_trims_the_resource_id_filter(): void
    {
        $user = $this->signIn();
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=%20card-1%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint);
    }
}
