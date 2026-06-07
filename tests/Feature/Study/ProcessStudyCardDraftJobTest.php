<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Actions\RecordStudyCardDraftSyncEntryAction;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Jobs\ProcessStudyCardDraft;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessStudyCardDraftJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_the_target_draft(): void
    {
        $draft = StudyCardDraft::factory()->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        (new ProcessStudyCardDraft($draft->id))->handle(app(ProcessStudyCardDraftAction::class));

        $this->assertSame(StudyManualCardDraftStatus::Ready, $draft->refresh()->status);
    }

    public function test_it_has_a_visible_retry_and_uniqueness_envelope(): void
    {
        $draftId = strtolower((string) str()->ulid());
        $job = new ProcessStudyCardDraft('  '.strtoupper($draftId).'  ');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(4, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertSame($draftId, $job->uniqueId());
    }

    public function test_it_marks_generating_drafts_error_when_final_attempts_fail(): void
    {
        $draft = StudyCardDraft::factory()->create([
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

        (new ProcessStudyCardDraft($draft->id))->failed(new RuntimeException('Worker infrastructure failed.'));

        $draft->refresh();

        $this->assertSame(StudyManualCardDraftStatus::Error, $draft->status);
        $this->assertSame(ProcessStudyCardDraft::EXHAUSTED_ERROR_MESSAGE, $draft->error_message);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame($draft->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame(StudyManualCardDraftStatus::Error->value, $entry->payload['status']);
        $this->assertSame(ProcessStudyCardDraft::EXHAUSTED_ERROR_MESSAGE, $entry->payload['error_message']);
    }

    public function test_failed_callback_persists_error_state_when_sync_recording_fails(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->app->bind(
            RecordStudyCardDraftSyncEntryAction::class,
            static fn (): RecordStudyCardDraftSyncEntryAction => new class extends RecordStudyCardDraftSyncEntryAction
            {
                public function __construct() {}

                public function handle(
                    StudyCardDraft $draft,
                    SyncFeedOperation $operation,
                    ?CarbonInterface $deletedAt = null,
                ): SyncFeedEntry {
                    throw new RuntimeException('Sync feed unavailable.');
                }
            },
        );

        (new ProcessStudyCardDraft($draft->id))->failed(new RuntimeException('Worker infrastructure failed.'));

        $draft->refresh();

        $this->assertSame(StudyManualCardDraftStatus::Error, $draft->status);
        $this->assertSame(ProcessStudyCardDraft::EXHAUSTED_ERROR_MESSAGE, $draft->error_message);
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_failed_callback_does_not_touch_terminal_or_committed_drafts(): void
    {
        $readyDraft = StudyCardDraft::factory()->ready()->create([
            'error_message' => 'Keep ready marker.',
        ]);
        $erroredDraft = StudyCardDraft::factory()->failed()->create([
            'error_message' => 'Keep error marker.',
        ]);
        $committedDraft = StudyCardDraft::factory()->create([
            'committed_card_id' => strtolower((string) str()->ulid()),
            'error_message' => 'Keep committed marker.',
        ]);
        $originalReadyUpdatedAt = $readyDraft->updated_at?->toJSON();
        $originalErroredUpdatedAt = $erroredDraft->updated_at?->toJSON();
        $originalCommittedUpdatedAt = $committedDraft->updated_at?->toJSON();

        (new ProcessStudyCardDraft($readyDraft->id))->failed(new RuntimeException('Ignored.'));
        (new ProcessStudyCardDraft($erroredDraft->id))->failed(new RuntimeException('Ignored.'));
        (new ProcessStudyCardDraft($committedDraft->id))->failed(new RuntimeException('Ignored.'));

        $this->assertSame(StudyManualCardDraftStatus::Ready, $readyDraft->refresh()->status);
        $this->assertSame('Keep ready marker.', $readyDraft->error_message);
        $this->assertSame($originalReadyUpdatedAt, $readyDraft->updated_at?->toJSON());

        $this->assertSame(StudyManualCardDraftStatus::Error, $erroredDraft->refresh()->status);
        $this->assertSame('Keep error marker.', $erroredDraft->error_message);
        $this->assertSame($originalErroredUpdatedAt, $erroredDraft->updated_at?->toJSON());

        $this->assertSame(StudyManualCardDraftStatus::Generating, $committedDraft->refresh()->status);
        $this->assertSame('Keep committed marker.', $committedDraft->error_message);
        $this->assertSame($originalCommittedUpdatedAt, $committedDraft->updated_at?->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_failed_callback_ignores_missing_drafts(): void
    {
        (new ProcessStudyCardDraft(strtolower((string) str()->ulid())))
            ->failed(new RuntimeException('Ignored.'));

        $this->assertDatabaseCount('study_card_drafts', 0);
    }
}
