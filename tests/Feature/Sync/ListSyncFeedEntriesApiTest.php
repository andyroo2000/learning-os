<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSyncFeedEntriesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_sync_feed_entries_for_the_authenticated_user_after_a_checkpoint(): void
    {
        $user = $this->signIn();
        $before = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $first = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
            'server_recorded_at' => now()->subMinute(),
            'payload' => ['id' => 'card-1', 'front' => 'Question'],
        ]);
        $second = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'deck-1',
            'operation' => SyncFeedOperation::Delete,
            'server_recorded_at' => now(),
            'payload' => null,
        ]);
        $otherUserEntry = SyncFeedEntry::factory()->create(['user_id' => User::factory()->create()->id]);

        $response = $this->getJson("/api/sync/feed?after_checkpoint={$before->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $first->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $before->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'checkpoint',
                        'domain',
                        'resource_type',
                        'resource_id',
                        'operation',
                        'server_recorded_at',
                        'payload',
                    ],
                ],
                'meta' => [
                    'after_checkpoint',
                    'next_checkpoint',
                    'has_more',
                    'per_page',
                ],
            ])
            ->assertJsonFragment([
                'checkpoint' => $first->checkpoint,
                'domain' => 'flashcards',
                'resource_type' => 'card',
                'resource_id' => 'card-1',
                'operation' => SyncFeedOperation::Update->value,
                'server_recorded_at' => $first->server_recorded_at->toJSON(),
                'payload' => ['id' => 'card-1', 'front' => 'Question'],
            ])
            ->assertJsonFragment([
                'checkpoint' => $second->checkpoint,
                'operation' => SyncFeedOperation::Delete->value,
                'payload' => null,
            ])
            ->assertJsonMissing([
                'checkpoint' => $before->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $otherUserEntry->checkpoint,
            ]);
    }

    public function test_it_serves_flashcard_tombstone_payloads_written_by_api_deletes(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $this->assertNotNull($card->front_text);
        $this->assertNotNull($card->back_text);

        $deleteResponse = $this->deleteJson("/api/cards/{$card->id}");

        $deleteResponse->assertNoContent();

        $response = $this->getJson('/api/sync/feed?domain=flashcards');

        $response->assertOk();

        $deleteEntries = collect($response->json('data'))
            ->filter(fn (array $entry): bool => $entry['operation'] === SyncFeedOperation::Delete->value
                && (string) $entry['resource_id'] === (string) $card->id)
            ->values();

        $this->assertCount(1, $deleteEntries, 'Expected exactly one Delete entry for the card in the sync feed.');

        $deleteEntry = $deleteEntries->first();

        $this->assertSame('flashcards', $deleteEntry['domain']);
        $this->assertSame('card', $deleteEntry['resource_type']);
        $this->assertSame(SyncFeedOperation::Delete->value, $deleteEntry['operation']);
        $this->assertIsString($deleteEntry['server_recorded_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleteEntry['server_recorded_at']);
        $this->assertSame($card->id, $deleteEntry['payload']['id']);
        $this->assertSame($card->deck_id, $deleteEntry['payload']['deck_id']);
        $this->assertSame($card->front_text, $deleteEntry['payload']['front_text']);
        $this->assertSame($card->back_text, $deleteEntry['payload']['back_text']);
        // Sync snapshots use Carbon::toJSON(), matching API resource timestamp serialization.
        $this->assertIsString($deleteEntry['payload']['created_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleteEntry['payload']['created_at']);
        $this->assertNotNull($deleteEntry['payload']['updated_at']);
        $this->assertIsString($deleteEntry['payload']['updated_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleteEntry['payload']['updated_at']);
        $this->assertNotNull($deleteEntry['payload']['deleted_at']);
        $this->assertIsString($deleteEntry['payload']['deleted_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleteEntry['payload']['deleted_at']);
        // This test's fresh user has no earlier feed writes; advancing by next_checkpoint must include the tombstone.
        $this->assertSame($deleteEntry['checkpoint'], $response->json('meta.next_checkpoint'));
    }

    public function test_it_returns_an_empty_list_when_the_user_has_no_new_entries(): void
    {
        $user = $this->signIn();
        $entry = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/sync/feed?after_checkpoint={$entry->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public function test_it_lists_the_full_feed_when_after_checkpoint_is_omitted(): void
    {
        $user = $this->signIn();
        $first = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $first->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.next_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_filters_entries_by_domain(): void
    {
        $user = $this->signIn();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $flashcards->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_trims_the_domain_filter(): void
    {
        $user = $this->signIn();
        $flashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=%20flashcards%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $flashcards->checkpoint);
    }

    public function test_it_returns_a_stale_checkpoint_response_when_the_bookmark_is_before_the_user_feed_window(): void
    {
        // Make the target user's first checkpoint nonzero so checkpoint - 1 still exercises stale replay.
        SyncFeedEntry::factory()->create(['user_id' => User::factory()->create()->id]);
        $user = $this->signIn();
        $oldest = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?after_checkpoint='.($oldest->checkpoint - 1));

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Sync checkpoint is stale; perform a full resource resync.')
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $oldest->checkpoint - 1)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldest->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_domain_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);
        $oldestFlashcard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$media->checkpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldestFlashcard->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_an_empty_page_when_the_filtered_domain_has_no_entries(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$media->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.next_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_it_reports_no_more_entries_when_the_page_is_exactly_full(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_uses_next_checkpoint_to_continue_to_the_next_page(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $firstPage = $this->getJson('/api/sync/feed?per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?after_checkpoint={$nextCheckpoint}&per_page=2");

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $third->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $third->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_uses_next_checkpoint_to_continue_domain_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $thirdFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.next_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdFlashcards->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $thirdFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_uses_the_default_page_size_when_omitted(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(CursorPagination::DEFAULT_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::DEFAULT_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public function test_it_accepts_the_minimum_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(2)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page='.CursorPagination::MIN_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MIN_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::MIN_PAGE_SIZE);
    }

    public function test_it_accepts_the_maximum_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->count(CursorPagination::MAX_PAGE_SIZE + 1)->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page='.CursorPagination::MAX_PAGE_SIZE);

        $response
            ->assertOk()
            ->assertJsonCount(CursorPagination::MAX_PAGE_SIZE, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.per_page', CursorPagination::MAX_PAGE_SIZE);
    }

    public function test_it_rejects_a_page_size_above_the_maximum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page='.(CursorPagination::MAX_PAGE_SIZE + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_a_page_size_below_the_minimum(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page=0');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_negative_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint=-1');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_non_integer_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint=abc');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_domain_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_domain_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain='.str_repeat('a', SyncFeedEntry::MAX_DOMAIN_LENGTH + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_domain_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain[]=flashcards');

        $response->assertUnprocessable();
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/sync/feed');

        $response->assertUnauthorized();
    }
}
