<?php

namespace Tests\Feature\Sync;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Services\StudyCardDraftEnricher;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Jobs\ProcessStudyCardDraft;
use App\Models\User;
use App\Support\Pagination\CursorPagination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Media\AssertsMediaAssetSyncFeedEntries;
use Tests\TestCase;

class ListSyncFeedEntryPayloadApiTest extends TestCase
{
    use AssertsMediaAssetSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_lists_sync_feed_entries_for_the_authenticated_user_after_a_checkpoint(): void
    {
        [$before, $first, $second, $otherUserEntry] = $this->feedEntriesForAuthenticatedUser();

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

    /**
     * @return array{SyncFeedEntry, SyncFeedEntry, SyncFeedEntry, SyncFeedEntry}
     */
    private function feedEntriesForAuthenticatedUser(): array
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

        return [$before, $first, $second, $otherUserEntry];
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
        $this->assertJsonTimestamp($deleteEntry['server_recorded_at']);
        $this->assertSame($card->id, $deleteEntry['payload']['id']);
        $this->assertSame($card->deck_id, $deleteEntry['payload']['deck_id']);
        $this->assertSame($card->front_text, $deleteEntry['payload']['front_text']);
        $this->assertSame($card->back_text, $deleteEntry['payload']['back_text']);
        // Sync snapshots use Carbon::toJSON(), matching API resource timestamp serialization.
        $this->assertJsonTimestamp($deleteEntry['payload']['created_at']);
        $this->assertJsonTimestamp($deleteEntry['payload']['updated_at']);
        $this->assertJsonTimestamp($deleteEntry['payload']['deleted_at']);
        // This test's fresh user has no earlier feed writes; advancing by next_checkpoint must include the tombstone.
        $this->assertSame($deleteEntry['checkpoint'], $response->json('meta.next_checkpoint'));
    }

    public function test_it_serves_review_event_tombstone_payloads_written_by_api_undos(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $createResponse = $this->postJson('/api/card-review-events', [
            'card_id' => $card->id,
            'rating' => 'good',
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $createResponse->assertCreated();

        $reviewEventId = $createResponse->json('data.id');

        $this->deleteJson("/api/card-review-events/{$reviewEventId}")
            ->assertOk();

        $response = $this->getJson("/api/sync/feed?domain=reviews&resource_type=card_review_event&resource_id={$reviewEventId}&operation=delete");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.domain', 'reviews')
            ->assertJsonPath('data.0.resource_type', 'card_review_event')
            ->assertJsonPath('data.0.resource_id', $reviewEventId)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.payload.id', $reviewEventId)
            ->assertJsonPath('data.0.payload.card_id', $card->id);

        $deleteEntry = $response->json('data.0');

        $this->assertJsonTimestamp($deleteEntry['payload']['deleted_at']);
        // Undo also records a card update after the review-event tombstone.
        $nextCheckpoint = $response->json('meta.next_checkpoint');
        $this->assertIsInt($deleteEntry['checkpoint']);
        $this->assertIsInt($nextCheckpoint);
        $this->assertGreaterThan($deleteEntry['checkpoint'], $nextCheckpoint);
    }

    public function test_it_serves_media_asset_manifest_snapshots_written_by_api_writes(): void
    {
        $this->signIn();

        $createResponse = $this->postJson('/api/media-assets', [
            'disk' => 'media',
            'path' => 'uploads/sync-feed-example.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 123_456,
            'public_url' => 'https://cdn.example.test/uploads/sync-feed-example.jpg',
            'checksum_sha256' => str_repeat('c', 64),
            'original_filename' => 'sync-feed-example.jpg',
        ]);

        $createResponse->assertCreated();

        $mediaAssetId = $createResponse->json('data.id');

        $this->deleteJson("/api/media-assets/{$mediaAssetId}")
            ->assertNoContent();

        $response = $this->getJson("/api/sync/feed?domain=media&resource_type=media_asset&resource_id={$mediaAssetId}");

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.domain', MediaAssetSyncPayload::DOMAIN)
            ->assertJsonPath('meta.resource_type', MediaAssetSyncPayload::RESOURCE_TYPE)
            ->assertJsonPath('meta.resource_id', $mediaAssetId)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Create->value)
            ->assertJsonPath('data.1.operation', SyncFeedOperation::Delete->value);

        $createEntry = $response->json('data.0');
        $deleteEntry = $response->json('data.1');

        foreach ([$createEntry, $deleteEntry] as $entry) {
            $this->assertSame(MediaAssetSyncPayload::DOMAIN, $entry['domain']);
            $this->assertSame(MediaAssetSyncPayload::RESOURCE_TYPE, $entry['resource_type']);
            $this->assertSame($mediaAssetId, $entry['resource_id']);
            $this->assertJsonTimestamp($entry['server_recorded_at']);
            $this->assertSame($mediaAssetId, $entry['payload']['id']);
            $this->assertNull($entry['payload']['import_job_id']);
            $this->assertNull($entry['payload']['source_kind']);
            $this->assertNull($entry['payload']['source_media_ref']);
            $this->assertNull($entry['payload']['source_filename']);
            $this->assertSame('https://cdn.example.test/uploads/sync-feed-example.jpg', $entry['payload']['url']);
            $this->assertSame("/api/media-assets/{$mediaAssetId}/content", $entry['payload']['content_url']);
            $this->assertSame('image/jpeg', $entry['payload']['mime_type']);
            $this->assertSame(123_456, $entry['payload']['size_bytes']);
            $this->assertSame(str_repeat('c', 64), $entry['payload']['checksum_sha256']);
            $this->assertSame('sync-feed-example.jpg', $entry['payload']['original_filename']);
            $this->assertJsonTimestamp($entry['payload']['created_at']);
            $this->assertJsonTimestamp($entry['payload']['updated_at']);
        }

        $this->assertLessThan($deleteEntry['checkpoint'], $createEntry['checkpoint']);
        $this->assertSame($deleteEntry['checkpoint'], $response->json('meta.next_checkpoint'));
    }

    public function test_it_serves_card_media_tombstones_written_by_media_asset_api_deletes(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->for($user)->create();
        $card->mediaAssets()->attach($mediaAsset->id);
        $pivot = $card->mediaAssets()->whereKey($mediaAsset->id)->first()?->pivot;

        $this->assertNotNull($pivot);

        $this->deleteJson("/api/media-assets/{$mediaAsset->id}")
            ->assertNoContent();

        $resourceId = CardMediaSyncPayload::resourceId($card->id, $mediaAsset->id);
        $mediaAssetEntry = $this->assertMediaAssetSyncPayloadRecorded($mediaAsset, SyncFeedOperation::Delete);
        $response = $this->getJson('/api/sync/feed?domain=media&resource_type=card_media&resource_id='.rawurlencode($resourceId).'&operation=delete');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.domain', CardMediaSyncPayload::DOMAIN)
            ->assertJsonPath('meta.resource_type', CardMediaSyncPayload::RESOURCE_TYPE)
            ->assertJsonPath('meta.resource_id', $resourceId)
            ->assertJsonPath('meta.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.domain', CardMediaSyncPayload::DOMAIN)
            ->assertJsonPath('data.0.resource_type', CardMediaSyncPayload::RESOURCE_TYPE)
            ->assertJsonPath('data.0.resource_id', $resourceId)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.payload.card_id', $card->id)
            ->assertJsonPath('data.0.payload.media_asset_id', $mediaAsset->id)
            ->assertJsonPath('data.0.payload.deck_id', $card->deck_id)
            ->assertJsonPath('data.0.payload.course_id', null)
            ->assertJsonPath('data.0.payload.created_at', $pivot->created_at->toJSON())
            ->assertJsonPath('data.0.payload.updated_at', $pivot->updated_at->toJSON());

        $cardMediaEntry = $response->json('data.0');

        $this->assertJsonTimestamp($cardMediaEntry['server_recorded_at']);
        // Filtered replay advances to the user's global high-water checkpoint, here the later media-asset tombstone.
        $this->assertSame($mediaAssetEntry->checkpoint, $response->json('meta.next_checkpoint'));
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

        $this->assertJsonTimestamp($deleteEntry['payload']['deleted_at']);
        $this->assertSame($deleteEntry['checkpoint'], $response->json('meta.next_checkpoint'));
    }

    public function test_it_serves_study_card_draft_lifecycle_entries_written_by_api_writes(): void
    {
        Queue::fake();
        $this->signIn();

        $createResponse = $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog'],
        ]);

        $createResponse->assertCreated();

        $draftId = $createResponse->json('id');

        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draftId,
        );

        $this->mock(StudyCardDraftEnricher::class)
            ->shouldReceive('enrich')
            ->once()
            ->andReturn([
                'prompt' => ['cueText' => '犬', 'cueReading' => '犬[いぬ]'],
                'answer' => ['meaning' => 'dog', 'expression' => '犬'],
                'imagePrompt' => null,
            ]);
        app(ProcessStudyCardDraftAction::class)->handle($draftId);

        $this->patchJson("/api/study/card-drafts/{$draftId}", [
            'prompt' => ['cueText' => '犬'],
            'answer' => ['meaning' => 'dog', 'reading' => 'inu'],
        ])->assertOk();

        $this->deleteJson("/api/study/card-drafts/{$draftId}")
            ->assertNoContent();

        $response = $this->getJson("/api/sync/feed?domain=study&resource_type=study_card_draft&resource_id={$draftId}");

        $response
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.domain', 'study')
            ->assertJsonPath('meta.resource_type', 'study_card_draft')
            ->assertJsonPath('meta.resource_id', $draftId)
            ->assertJsonPath('data.0.operation', SyncFeedOperation::Create->value)
            ->assertJsonPath('data.1.operation', SyncFeedOperation::Update->value)
            ->assertJsonPath('data.2.operation', SyncFeedOperation::Update->value)
            ->assertJsonPath('data.3.operation', SyncFeedOperation::Delete->value)
            ->assertJsonPath('data.0.payload.status', 'generating')
            ->assertJsonPath('data.1.payload.status', 'ready')
            ->assertJsonPath('data.2.payload.answer_json.reading', 'inu')
            ->assertJsonPath('data.3.payload.id', $draftId);

        $deleteEntry = $response->json('data.3');

        $this->assertJsonTimestamp($deleteEntry['payload']['deleted_at']);
        $this->assertSame($deleteEntry['checkpoint'], $response->json('meta.next_checkpoint'));
    }
}
