<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class ProcessStudyCardDraftActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_marks_generating_seeded_manual_drafts_ready(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'error_message' => 'Previous failure',
        ]);

        $processed = app(ProcessStudyCardDraftAction::class)->handle('  '.strtoupper($draft->id).'  ');

        $processed?->refresh();

        $this->assertNotNull($processed);
        $this->assertSame($draft->id, $processed?->id);
        $this->assertSame(StudyManualCardDraftStatus::Ready, $processed?->status);
        $this->assertSame(['cueText' => '会社'], $processed?->prompt_json);
        $this->assertSame(['meaning' => 'company'], $processed?->answer_json);
        $this->assertNull($processed?->error_message);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($processed, SyncFeedOperation::Update);

        $this->assertSame('ready', $entry->payload['status']);
        $this->assertNull($entry->payload['error_message']);
    }

    public function test_it_marks_invalid_generating_drafts_failed_without_leaving_stale_outputs(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'prompt_json' => ['cueText' => ['nested' => ['too' => ['deep' => ['for' => ['the' => ['manual' => ['draft' => ['processor']]]]]]]]],
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'stale.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'stale.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
        ]);

        $processed = app(ProcessStudyCardDraftAction::class)->handle($draft->id);

        $processed?->refresh();

        $this->assertNotNull($processed);
        $this->assertSame(StudyManualCardDraftStatus::Error, $processed?->status);
        $this->assertSame('prompt must be 8 levels deep or fewer.', $processed?->error_message);
        $this->assertNull($processed?->preview_audio_json);
        $this->assertNull($processed?->preview_audio_role);
        $this->assertNull($processed?->preview_image_json);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($processed, SyncFeedOperation::Update);

        $this->assertSame('error', $entry->payload['status']);
        $this->assertSame('prompt must be 8 levels deep or fewer.', $entry->payload['error_message']);
    }

    public function test_it_does_not_reprocess_terminal_or_missing_drafts(): void
    {
        $readyDraft = StudyCardDraft::factory()->ready()->create();
        $erroredDraft = StudyCardDraft::factory()->failed()->create([
            'error_message' => 'Still waiting for an explicit retry.',
        ]);
        $originalReadyUpdatedAt = $readyDraft->updated_at?->toJSON();
        $originalErroredUpdatedAt = $erroredDraft->updated_at?->toJSON();

        $processedReady = app(ProcessStudyCardDraftAction::class)->handle($readyDraft->id);
        $processedError = app(ProcessStudyCardDraftAction::class)->handle($erroredDraft->id);

        $this->assertSame($readyDraft->id, $processedReady?->id);
        $this->assertSame(StudyManualCardDraftStatus::Ready, $processedReady?->refresh()->status);
        $this->assertSame($originalReadyUpdatedAt, $processedReady?->updated_at?->toJSON());

        $this->assertSame($erroredDraft->id, $processedError?->id);
        $this->assertSame(StudyManualCardDraftStatus::Error, $processedError?->refresh()->status);
        $this->assertSame('Still waiting for an explicit retry.', $processedError?->error_message);
        $this->assertSame($originalErroredUpdatedAt, $processedError?->updated_at?->toJSON());
        $this->assertNull(app(ProcessStudyCardDraftAction::class)->handle(strtolower((string) str()->ulid())));
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_returns_null_for_malformed_draft_ids_without_querying_drafts(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $processed = app(ProcessStudyCardDraftAction::class)->handle('not-a-ulid');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertNull($processed);
        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_card_drafts')),
            'Malformed draft IDs should return null before querying study_card_drafts.',
        );
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_does_not_process_committed_generating_drafts(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'committed_card_id' => strtolower((string) str()->ulid()),
            'error_message' => 'Keep this defensive marker.',
        ]);
        $originalUpdatedAt = $draft->updated_at?->toJSON();

        $processed = app(ProcessStudyCardDraftAction::class)->handle($draft->id);

        $processed?->refresh();

        $this->assertSame($draft->id, $processed?->id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $processed?->status);
        $this->assertSame($draft->committed_card_id, $processed?->committed_card_id);
        $this->assertSame('Keep this defensive marker.', $processed?->error_message);
        $this->assertSame($originalUpdatedAt, $processed?->updated_at?->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }
}
