<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ProcessStudyCardDraftAction;
use App\Domain\Study\Actions\RetryStudyCardDraftAction;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Jobs\ProcessStudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class RetryStudyCardDraftActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_retries_an_errored_manual_study_card_draft(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'creation_kind' => StudyCardCreationKind::ProductionImage,
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'image_placement' => StudyCardImagePlacement::Both,
            'image_prompt' => 'A company office',
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Prompt,
            'preview_image_json' => [
                'id' => 'image-1',
                'filename' => 'kaisha.webp',
                'mediaKind' => 'image',
                'source' => 'generated',
            ],
            'error_message' => 'Generation failed.',
        ]);

        $processedDraftIds = [];

        $retried = app(RetryStudyCardDraftAction::class)->handle(
            $user->id,
            strtoupper($draft->id),
            afterCommit: static function (string $draftId) use (&$processedDraftIds): void {
                $processedDraftIds[] = $draftId;

                ProcessStudyCardDraft::dispatch($draftId);
            },
        );

        $retried->refresh();

        $this->assertSame($draft->id, $retried->id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $retried->status);
        $this->assertSame(StudyCardCreationKind::ProductionImage, $retried->creation_kind);
        $this->assertSame(['cueText' => '会社'], $retried->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $retried->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $retried->image_placement);
        $this->assertSame('A company office', $retried->image_prompt);
        $this->assertNull($retried->preview_audio_json);
        $this->assertNull($retried->preview_audio_role);
        $this->assertNull($retried->preview_image_json);
        $this->assertNull($retried->error_message);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($retried, SyncFeedOperation::Update);

        $this->assertSame('generating', $entry->payload['status']);
        $this->assertNull($entry->payload['preview_audio_json']);
        $this->assertNull($entry->payload['error_message']);
        $this->assertSame([$draft->id], $processedDraftIds);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        app(RetryStudyCardDraftAction::class)->handle($userId, strtolower((string) str()->ulid()));
    }

    public function test_it_hides_cross_user_drafts(): void
    {
        $otherDraft = StudyCardDraft::factory()->failed()->create();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(RetryStudyCardDraftAction::class)->handle(User::factory()->create()->id, $otherDraft->id);
    }

    public function test_it_hides_missing_drafts(): void
    {
        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(RetryStudyCardDraftAction::class)->handle(User::factory()->create()->id, strtolower((string) str()->ulid()));
    }

    public function test_it_hides_malformed_draft_ids_without_querying_drafts(): void
    {
        $userId = User::factory()->create()->id;

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(RetryStudyCardDraftAction::class)->handle($userId, 'not-a-ulid');
            $this->fail('Expected malformed draft IDs to be hidden as not found.');
        } catch (StudyCardDraftNotFoundException $exception) {
            $this->assertSame('Study card draft not found.', $exception->getMessage());
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_card_drafts')),
            'Malformed draft IDs should return not-found before querying study_card_drafts.',
        );
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_returns_generating_drafts_for_idempotent_transport_retries(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->for($user)->create();
        $this->assertNotNull($draft->updated_at);
        $originalUpdatedAt = $draft->updated_at->toJSON();
        $processedDraftIds = [];

        $retried = app(RetryStudyCardDraftAction::class)->handle(
            $user->id,
            $draft->id,
            afterCommit: static function (string $draftId) use (&$processedDraftIds): void {
                $processedDraftIds[] = $draftId;

                ProcessStudyCardDraft::dispatch($draftId);
            },
        );

        $retried->refresh();

        $this->assertSame($draft->id, $retried->id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $retried->status);
        $this->assertNotNull($retried->updated_at);
        $this->assertSame($originalUpdatedAt, $retried->updated_at->toJSON());
        $this->assertSame(0, SyncFeedEntry::query()->count());
        $this->assertSame([$draft->id], $processedDraftIds);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    #[DataProvider('nonRetryableStatusProvider')]
    public function test_it_rejects_non_retryable_drafts(StudyManualCardDraftStatus $status): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->for($user)->create([
            'status' => $status,
        ]);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Only errored drafts can be retried.');

        app(RetryStudyCardDraftAction::class)->handle($user->id, $draft->id);
    }

    public function test_it_rejects_committed_drafts(): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'committed_card_id' => strtolower((string) str()->ulid()),
        ]);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Committed drafts cannot be retried.');

        app(RetryStudyCardDraftAction::class)->handle($user->id, $draft->id);
    }

    public function test_it_reads_current_db_status_not_creation_time_snapshot(): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create();

        StudyCardDraft::query()
            ->whereKey($draft->id)
            ->update(['status' => StudyManualCardDraftStatus::Ready->value]);

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Only errored drafts can be retried.');

        app(RetryStudyCardDraftAction::class)->handle($user->id, $draft->id);
    }

    public function test_retried_drafts_can_be_completed_by_the_generation_processor(): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $retried = app(RetryStudyCardDraftAction::class)->handle($user->id, $draft->id);

        $processed = app(ProcessStudyCardDraftAction::class)->handle($retried->id);

        $this->assertSame(StudyManualCardDraftStatus::Ready, $processed?->refresh()->status);
        $this->assertNull($processed?->error_message);
    }

    public function test_retried_drafts_can_fail_again_when_generation_payloads_are_invalid(): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->failed()->for($user)->create([
            'prompt_json' => ['cueText' => ['nested' => ['too' => ['deep' => ['for' => ['the' => ['manual' => ['draft' => ['processor']]]]]]]]],
            'answer_json' => ['meaning' => 'company'],
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'stale.mp3',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
            'error_message' => 'Previous failure.',
        ]);

        app(RetryStudyCardDraftAction::class)->handle($user->id, $draft->id);
        $draft->refresh();

        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->error_message);

        $processed = app(ProcessStudyCardDraftAction::class)->handle($draft->id);

        $this->assertSame(StudyManualCardDraftStatus::Error, $processed?->refresh()->status);
        $this->assertSame('prompt must be 8 levels deep or fewer.', $processed?->error_message);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveUserIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    /**
     * @return array<string, array{StudyManualCardDraftStatus}>
     */
    public static function nonRetryableStatusProvider(): array
    {
        $statuses = [];

        foreach (StudyManualCardDraftStatus::cases() as $status) {
            if (in_array($status, [StudyManualCardDraftStatus::Error, StudyManualCardDraftStatus::Generating], true)) {
                continue;
            }

            $statuses[$status->value] = [$status];
        }

        return $statuses;
    }
}
