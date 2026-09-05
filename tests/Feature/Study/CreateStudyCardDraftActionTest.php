<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Actions\PrepareStudyCardDraftQueueSlotAction;
use App\Domain\Study\Actions\RecordStudyCardDraftSyncEntryAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Jobs\ProcessStudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\Support\AssertsStudyCardDraftSyncFeedEntries;
use Tests\TestCase;

class CreateStudyCardDraftActionTest extends TestCase
{
    use AssertsStudyCardDraftSyncFeedEntries;
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_it_creates_a_generating_study_card_draft(): void
    {
        $user = User::factory()->create();

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::ProductionImage,
            cardType: CardType::Production,
            promptJson: ['cueText' => 'company'],
            answerJson: ['expression' => '会社', 'meaning' => 'company'],
            imagePlacement: StudyCardImagePlacement::Both,
            imagePrompt: '  A sunny office  ',
        ));

        $draft->refresh();

        $this->assertSame($user->id, $draft->user_id);
        $this->assertSame(StudyManualCardDraftStatus::Generating, $draft->status);
        $this->assertSame(StudyCardCreationKind::ProductionImage, $draft->creation_kind);
        $this->assertSame(CardType::Production, $draft->card_type);
        $this->assertSame(['cueText' => 'company'], $draft->prompt_json);
        $this->assertSame(['expression' => '会社', 'meaning' => 'company'], $draft->answer_json);
        $this->assertSame(StudyCardImagePlacement::Both, $draft->image_placement);
        $this->assertSame('A sunny office', $draft->image_prompt);
        $this->assertNull($draft->preview_audio_json);
        $this->assertNull($draft->preview_audio_role);
        $this->assertNull($draft->preview_image_json);
        $this->assertNull($draft->error_message);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertStudyCardDraftSyncPayloadRecorded($draft, SyncFeedOperation::Create);

        $this->assertSame('generating', $entry->payload['status']);
        $this->assertSame('production-image', $entry->payload['creation_kind']);
        $this->assertSame('A sunny office', $entry->payload['image_prompt']);
    }

    public function test_it_calls_the_draft_lifecycle_callback_after_creating_the_draft_and_sync_entry(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $processedDraftIds = [];

        $draft = app(CreateStudyCardDraftAction::class)->handle(
            CreateStudyCardDraftData::fromInput(
                userId: $user->id,
                creationKind: StudyCardCreationKind::TextRecognition,
                cardType: CardType::Recognition,
                promptJson: ['cueText' => '犬'],
                answerJson: ['meaning' => 'dog'],
            ),
            afterCommit: static function (string $draftId) use (&$processedDraftIds): void {
                $processedDraftIds[] = $draftId;

                ProcessStudyCardDraft::dispatch($draftId);
            },
        );

        $this->assertSame([$draft->id], $processedDraftIds);
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'user_id' => $user->id,
            'status' => StudyManualCardDraftStatus::Generating->value,
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);
        Queue::assertPushedOn(
            ProcessStudyCardDraft::QUEUE_NAME,
            ProcessStudyCardDraft::class,
            fn (ProcessStudyCardDraft $job): bool => $job->draftId === $draft->id,
        );
    }

    public function test_it_returns_an_existing_draft_for_a_matching_client_id_retry_without_duplicate_side_effects(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $callbackCount = 0;
        $data = CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            id: strtoupper($id),
        );

        $created = app(CreateStudyCardDraftAction::class)->handle(
            $data,
            afterCommit: static function () use (&$callbackCount): void {
                $callbackCount++;
            },
        );
        $retried = app(CreateStudyCardDraftAction::class)->handle(
            $data,
            afterCommit: static function () use (&$callbackCount): void {
                $callbackCount++;
            },
        );

        $this->assertSame($id, $created->id);
        $this->assertSame($created->id, $retried->id);
        $this->assertTrue($created->wasRecentlyCreated);
        $this->assertFalse($retried->wasRecentlyCreated);
        $this->assertSame(1, $callbackCount);
        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_rejects_a_client_id_retry_with_different_creation_data(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            id: $id,
        ));

        try {
            app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
                userId: $user->id,
                creationKind: StudyCardCreationKind::TextRecognition,
                cardType: CardType::Recognition,
                promptJson: ['cueText' => '猫'],
                answerJson: ['meaning' => 'cat'],
                id: $id,
            ));

            $this->fail('Expected client draft ID conflict.');
        } catch (StudyCardDraftConflictException $exception) {
            $this->assertSame('Draft ID already exists with different creation data.', $exception->getMessage());
        }

        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_treats_reordered_nested_json_object_keys_as_the_same_retry_data(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        $created = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: [
                'cueText' => '犬',
                'cueImage' => ['id' => 'image-1', 'url' => '/media/image-1'],
            ],
            answerJson: ['meaning' => 'dog', 'expression' => '犬'],
            id: $id,
        ));

        $retried = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: [
                'cueImage' => ['url' => '/media/image-1', 'id' => 'image-1'],
                'cueText' => '犬',
            ],
            answerJson: ['expression' => '犬', 'meaning' => 'dog'],
            id: $id,
        ));

        $this->assertSame($created->id, $retried->id);
        $this->assertFalse($retried->wasRecentlyCreated);
        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_recovers_a_matching_client_id_insert_race_without_duplicate_side_effects(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $callbackCount = 0;
        $action = new CreateStudyCardDraftAction(
            app(PrepareStudyCardDraftQueueSlotAction::class),
            app(RecordStudyCardDraftSyncEntryAction::class),
            afterClientIdPrecheckMiss: static function () use ($id, $user): void {
                StudyCardDraft::factory()->for($user)->create([
                    'id' => $id,
                    'prompt_json' => ['cueText' => '犬'],
                    'answer_json' => ['answerText' => 'dog'],
                ]);
            },
        );

        $draft = $action->handle(
            CreateStudyCardDraftData::fromInput(
                userId: $user->id,
                creationKind: StudyCardCreationKind::TextRecognition,
                cardType: CardType::Recognition,
                promptJson: ['cueText' => '犬'],
                answerJson: ['answerText' => 'dog'],
                id: $id,
            ),
            afterCommit: static function () use (&$callbackCount): void {
                $callbackCount++;
            },
        );

        $this->assertSame($id, $draft->id);
        $this->assertFalse($draft->wasRecentlyCreated);
        $this->assertSame(0, $callbackCount);
        $this->assertDatabaseCount('study_card_drafts', 1);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_a_matching_concurrent_retry_wins_when_the_first_request_fills_the_final_queue_slot(): void
    {
        $user = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $action = new CreateStudyCardDraftAction(
            app(PrepareStudyCardDraftQueueSlotAction::class),
            app(RecordStudyCardDraftSyncEntryAction::class),
            afterClientIdPrecheckMiss: function () use ($id, $user): void {
                $this->insertCappedDraftRowsFor($user, $id);
            },
        );

        $draft = $action->handle(CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
            id: $id,
        ));

        $this->assertSame($id, $draft->id);
        $this->assertFalse($draft->wasRecentlyCreated);
        $this->assertDatabaseCount(
            'study_card_drafts',
            PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER,
        );
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_creates_when_the_user_draft_queue_is_full_without_side_effects(): void
    {
        $user = User::factory()->create();
        $this->insertCappedDraftRowsFor($user);

        try {
            app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
                userId: $user->id,
                creationKind: StudyCardCreationKind::TextRecognition,
                cardType: CardType::Recognition,
                promptJson: ['cueText' => '犬'],
                answerJson: ['answerText' => 'dog'],
            ));

            $this->fail('Expected queue full conflict.');
        } catch (StudyCardDraftConflictException $exception) {
            $this->assertSame('Draft queue is full. Delete some drafts before adding more.', $exception->getMessage());
        }

        $this->assertDatabaseCount('study_card_drafts', PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_counts_the_draft_queue_cap_per_user(): void
    {
        $fullUser = User::factory()->create();
        $creatingUser = User::factory()->create();
        $this->insertCappedDraftRowsFor($fullUser);

        $draft = app(CreateStudyCardDraftAction::class)->handle(CreateStudyCardDraftData::fromInput(
            userId: $creatingUser->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['answerText' => 'dog'],
        ));

        $this->assertSame($creatingUser->id, $draft->refresh()->user_id);
        $this->assertSame(
            PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER,
            $this->draftCountFor($fullUser),
        );
        $this->assertSame(1, $this->draftCountFor($creatingUser));
        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    private function draftCountFor(User $user): int
    {
        return StudyCardDraft::query()->where('user_id', $user->id)->count();
    }
}
