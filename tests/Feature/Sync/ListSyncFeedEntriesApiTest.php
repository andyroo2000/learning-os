<?php

namespace Tests\Feature\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            ->assertJsonPath('meta.current_checkpoint', $second->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', null)
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
                    'current_checkpoint',
                    'domain',
                    'resource_type',
                    'resource_id',
                    'operation',
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

    public function test_it_serves_course_entries_written_by_course_api_writes(): void
    {
        $this->signIn();

        $createResponse = $this->postJson('/api/courses', [
            'title' => 'Japanese Travel Foundations',
            'description' => 'Audio-first course for common travel scenarios.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);

        $createResponse->assertCreated();

        $courseId = $createResponse->json('data.id');

        $this->putJson("/api/courses/{$courseId}", [
            'title' => 'Japanese Travel Foundations',
            'description' => 'Airport and train-station conversations.',
        ])->assertOk();

        $this->deleteJson("/api/courses/{$courseId}")
            ->assertNoContent();

        $response = $this->getJson("/api/sync/feed?domain=courses&resource_type=course&resource_id={$courseId}");

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.domain', 'courses')
            ->assertJsonPath('meta.resource_type', 'course')
            ->assertJsonPath('meta.resource_id', $courseId)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Create->value)
            ->assertJsonPath('data.1.operation', SyncFeedOperation::Update->value)
            ->assertJsonPath('data.2.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.payload.title', 'Japanese Travel Foundations')
            ->assertJsonPath('data.0.payload.description', 'Audio-first course for common travel scenarios.')
            ->assertJsonPath('data.1.payload.description', 'Airport and train-station conversations.')
            ->assertJsonPath('data.2.payload.id', $courseId)
            ->assertJsonPath('data.2.payload.status', 'draft')
            ->assertJsonPath('data.2.payload.native_language', 'en')
            ->assertJsonPath('data.2.payload.target_language', 'ja');

        $deleteEntry = $response->json('data.2');

        $this->assertNotNull($deleteEntry['payload']['deleted_at']);
        $this->assertIsString($deleteEntry['payload']['deleted_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $deleteEntry['payload']['deleted_at']);
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
            ->assertJsonPath('meta.current_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.per_page', CursorPagination::DEFAULT_PAGE_SIZE);
    }

    public function test_it_reports_zero_current_checkpoint_when_the_user_feed_is_empty(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.current_checkpoint', 0)
            ->assertJsonPath('meta.next_checkpoint', 0)
            ->assertJsonPath('meta.has_more', false);
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
            ->assertJsonPath('meta.current_checkpoint', $second->checkpoint)
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
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_resource_type(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $response = $this->getJson('/api/sync/feed?resource_type=card');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $card->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deck->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_and_resource_type(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $mediaCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $card->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $mediaCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaCard->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaCard->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_resource_type_and_resource_id(): void
    {
        $user = $this->signIn();
        $targetCreate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Create,
        ]);
        $targetUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
        ]);
        $otherCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        $deckSameId = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'resource_id' => 'card-1',
        ]);
        $mediaSameId = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $targetCreate->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $targetUpdate->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $mediaSameId->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaSameId->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $otherCard->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $deckSameId->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaSameId->checkpoint,
            ]);
    }

    public function test_it_normalizes_uppercase_ulid_resource_id_filters(): void
    {
        $user = $this->signIn();
        $resourceId = strtolower((string) Str::ulid());
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => $resourceId,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=FLASHCARDS&resource_type=CARD&resource_id='.strtoupper($resourceId));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', $resourceId);
    }

    public function test_it_normalizes_uppercase_composite_resource_id_filters(): void
    {
        $user = $this->signIn();
        $cardId = strtolower((string) Str::ulid());
        $mediaAssetId = strtolower((string) Str::ulid());
        $resourceId = "{$cardId}:{$mediaAssetId}";
        $entry = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card_media',
            'resource_id' => $resourceId,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=MEDIA&resource_type=CARD_MEDIA&resource_id='.strtoupper($resourceId));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint)
            ->assertJsonPath('meta.domain', 'media')
            ->assertJsonPath('meta.resource_type', 'card_media')
            ->assertJsonPath('meta.resource_id', $resourceId);
    }

    public function test_it_filters_entries_by_operation(): void
    {
        $user = $this->signIn();
        $create = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Create,
        ]);
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $response = $this->getJson('/api/sync/feed?operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $create->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_operation_and_checkpoint_together(): void
    {
        $user = $this->signIn();
        $before = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $after = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$before->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $after->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $before->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $before->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_operation_with_resource_scope(): void
    {
        $user = $this->signIn();
        $targetDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $targetUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
            'operation' => SyncFeedOperation::Update,
        ]);
        $otherDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'asset',
            'resource_id' => 'asset-1',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $targetDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $otherDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $otherDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $targetUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $otherDelete->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_and_operation(): void
    {
        $user = $this->signIn();
        $flashcardDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $flashcardUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'operation' => SyncFeedOperation::Update,
        ]);
        $mediaDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $flashcardDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $mediaDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $mediaDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $flashcardUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $mediaDelete->checkpoint,
            ]);
    }

    public function test_it_filters_entries_by_domain_resource_type_and_operation(): void
    {
        $user = $this->signIn();
        $cardDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'operation' => SyncFeedOperation::Delete,
        ]);
        $cardUpdate = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'operation' => SyncFeedOperation::Update,
        ]);
        $deckDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $cardDelete->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $deckDelete->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deckDelete->checkpoint)
            ->assertJsonMissing([
                'checkpoint' => $cardUpdate->checkpoint,
            ])
            ->assertJsonMissing([
                'checkpoint' => $deckDelete->checkpoint,
            ]);
    }

    public function test_it_returns_an_empty_page_when_the_filtered_operation_has_no_entries(): void
    {
        $user = $this->signIn();
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$update->checkpoint}");

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_trims_the_resource_type_filter(): void
    {
        $user = $this->signIn();
        $card = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'resource_type' => 'card',
        ]);

        $response = $this->getJson('/api/sync/feed?resource_type=%20card%20');

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

        $response = $this->getJson('/api/sync/feed?domain=%20flashcards%20');

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

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=%20card-1%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('data.0.checkpoint', $entry->checkpoint);
    }

    public function test_it_trims_the_operation_filter(): void
    {
        $user = $this->signIn();
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?operation=%20delete%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint);
    }

    public function test_it_normalizes_the_operation_filter_case(): void
    {
        $user = $this->signIn();
        $delete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson('/api/sync/feed?operation=DELETE');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.checkpoint', $delete->checkpoint);
    }

    public function test_it_returns_a_stale_checkpoint_response_when_the_bookmark_is_before_the_user_feed_window(): void
    {
        $user = $this->signIn();
        $oldest = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
        ]);

        $response = $this->getJson('/api/sync/feed?after_checkpoint=4');

        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Sync checkpoint is stale; perform a full resource resync.')
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', 4)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldest->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_domain_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'media',
        ]);
        $oldestFlashcard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
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
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_an_operation_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $update = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);
        $oldestDelete = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);

        $response = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$update->checkpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldestDelete->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', null)
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_resource_type_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&after_checkpoint={$deck->checkpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_resource_type_only_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $deck = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);
        $oldestCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'media',
            'resource_type' => 'card',
        ]);

        $response = $this->getJson("/api/sync/feed?resource_type=card&after_checkpoint={$deck->checkpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldestCard->checkpoint)
            ->assertJsonPath('meta.domain', null)
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.required_action', 'full_resync')
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_a_resource_id_scoped_stale_checkpoint_response(): void
    {
        $user = $this->signIn();
        $otherCard = SyncFeedEntry::factory()->create([
            'checkpoint' => 4,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);
        $oldestTarget = SyncFeedEntry::factory()->create([
            'checkpoint' => 5,
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);

        $response = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&after_checkpoint={$otherCard->checkpoint}");

        $response
            ->assertConflict()
            ->assertJsonPath('reason', 'stale_sync_checkpoint')
            ->assertJsonPath('meta.after_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.oldest_available_checkpoint', $oldestTarget->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
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
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_complete_domain_filtered_page_advances_to_the_user_feed_high_water_mark(): void
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
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_cold_start_domain_filter_with_no_entries_advances_to_the_user_feed_high_water_mark(): void
    {
        $user = $this->signIn();
        $media = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'media',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards&after_checkpoint=0');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.after_checkpoint', 0)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_a_complete_domain_filtered_page_uses_the_domain_checkpoint_when_it_is_the_high_water_mark(): void
    {
        $user = $this->signIn();
        $firstFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);
        $secondFlashcards = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
        ]);

        $response = $this->getJson('/api/sync/feed?domain=flashcards');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.checkpoint', $firstFlashcards->checkpoint)
            ->assertJsonPath('data.1.checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_accepts_a_custom_page_size(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $second = SyncFeedEntry::factory()->create(['user_id' => $user->id]);
        $third = SyncFeedEntry::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/sync/feed?per_page=2');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_checkpoint', $third->checkpoint)
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
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdFlashcards->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondFlashcards->checkpoint)
            ->assertJsonPath('meta.current_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $media->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $media->checkpoint,
            ]);
    }

    public function test_it_uses_next_checkpoint_to_continue_resource_type_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $secondCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $thirdCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
        ]);
        $deck = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'deck',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondCard->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdCard->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondCard->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', null)
            ->assertJsonPath('meta.current_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $deck->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $deck->checkpoint,
            ]);
    }

    public function test_it_uses_next_checkpoint_to_continue_resource_id_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $secondTarget = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $thirdTarget = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-1',
        ]);
        $otherCard = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'domain' => 'flashcards',
            'resource_type' => 'card',
            'resource_id' => 'card-2',
        ]);

        $firstPage = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?domain=flashcards&resource_type=card&resource_id=card-1&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondTarget->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdTarget->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondTarget->checkpoint)
            ->assertJsonPath('meta.domain', 'flashcards')
            ->assertJsonPath('meta.resource_type', 'card')
            ->assertJsonPath('meta.resource_id', 'card-1')
            ->assertJsonPath('meta.current_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $otherCard->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $otherCard->checkpoint,
            ]);
    }

    public function test_it_uses_next_checkpoint_to_continue_operation_filtered_pages(): void
    {
        $user = $this->signIn();
        SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $secondDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $thirdDelete = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Delete,
        ]);
        $update = SyncFeedEntry::factory()->create([
            'user_id' => $user->id,
            'operation' => SyncFeedOperation::Update,
        ]);

        $firstPage = $this->getJson('/api/sync/feed?operation=delete&per_page=2');

        $nextCheckpoint = $firstPage->json('meta.next_checkpoint');

        $secondPage = $this->getJson("/api/sync/feed?operation=delete&after_checkpoint={$nextCheckpoint}&per_page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $secondDelete->checkpoint)
            ->assertJsonPath('meta.has_more', true);

        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.checkpoint', $thirdDelete->checkpoint)
            ->assertJsonPath('meta.after_checkpoint', $secondDelete->checkpoint)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('meta.current_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.next_checkpoint', $update->checkpoint)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissing([
                'checkpoint' => $update->checkpoint,
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

    public function test_it_rejects_array_page_sizes(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?per_page[]=10');

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

    public function test_it_rejects_array_checkpoints(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?after_checkpoint[]=1');

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

    public function test_it_rejects_blank_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_type_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type='.str_repeat('a', SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type[]=card');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_unknown_operation_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?operation=patch');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_blank_operation_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?operation=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_operation_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?operation[]=delete');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_id_without_domain_and_resource_type_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain', 'resource_type']);
    }

    public function test_it_rejects_resource_id_without_resource_type_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['resource_type']);
    }

    public function test_it_rejects_resource_id_without_domain_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?resource_type=card&resource_id=card-1');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    }

    public function test_it_rejects_blank_resource_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id=%20');

        $response->assertUnprocessable();
    }

    public function test_it_rejects_resource_id_filters_above_the_maximum_length(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id='.str_repeat('a', SyncFeedEntry::MAX_RESOURCE_ID_LENGTH + 1));

        $response->assertUnprocessable();
    }

    public function test_it_rejects_array_resource_id_filters(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/sync/feed?domain=flashcards&resource_type=card&resource_id[]=card-1');

        $response->assertUnprocessable();
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->getJson('/api/sync/feed');

        $response->assertUnauthorized();
    }
}
