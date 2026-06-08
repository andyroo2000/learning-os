<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Exceptions\CardValidationException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\CreateStudyCardFromDraftAction;
use App\Domain\Study\Actions\ResolveManualStudyDeckAction;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateStudyCardFromDraftActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_manual_study_card_from_an_owned_ready_draft(): void
    {
        $user = User::factory()->create();
        $draft = StudyCardDraft::factory()->ready()->for($user)->create([
            'creation_kind' => StudyCardCreationKind::ProductionText,
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition->value,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => now(),
        ]);
        $cardId = strtolower((string) str()->ulid());

        $result = app(CreateStudyCardFromDraftAction::class)->handle($user->id, $draft->id, $cardId);

        $this->assertTrue($result->wasCreated);
        $this->assertSame($cardId, $result->card->id);
        $this->assertSame(CardType::Production, $result->card->card_type);
        $this->assertSame(['cueText' => '会社'], $result->card->prompt_json);
        $this->assertSame(['meaning' => 'company'], $result->card->answer_json);
        $this->assertSame('vocab-group-1', $result->card->variant_group_id);
        $this->assertSame('sentence-1', $result->card->variant_sentence_id);
        $this->assertSame(VocabVariantKind::SentenceTextRecognition->value, $result->card->variant_kind);
        $this->assertSame(2, $result->card->variant_stage);
        $this->assertSame(VocabVariantStatus::Available->value, $result->card->variant_status);
        // The draft factory and committed card both store this timestamp at database second precision.
        $this->assertSame($draft->variant_unlocked_at->toJSON(), $result->card->variant_unlocked_at->toJSON());
        $this->assertSame('会社', $result->card->front_text);
        $this->assertSame('company', $result->card->back_text);
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $cardId,
        ]);

        $deck = Deck::query()->sole();
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_NAME, $deck->name);
        $this->assertTrue($deck->is_manual_study_deck);
        $this->assertSame($deck->id, $result->card->deck_id);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(3, $entries);
        $this->assertSame('deck', $entries[0]->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $entries[0]->operation);
        $this->assertSame('card', $entries[1]->resource_type);
        $this->assertSame($cardId, $entries[1]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[1]->operation);
        $this->assertSame('vocab-group-1', $entries[1]->payload['variant_group_id']);
        $this->assertSame('sentence-1', $entries[1]->payload['variant_sentence_id']);
        $this->assertSame(VocabVariantKind::SentenceTextRecognition->value, $entries[1]->payload['variant_kind']);
        $this->assertSame(2, $entries[1]->payload['variant_stage']);
        $this->assertSame(VocabVariantStatus::Available->value, $entries[1]->payload['variant_status']);
        $this->assertSame($draft->variant_unlocked_at->toJSON(), $entries[1]->payload['variant_unlocked_at']);
        $this->assertSame('study_card_draft', $entries[2]->resource_type);
        $this->assertSame($draft->id, $entries[2]->resource_id);
        $this->assertSame(SyncFeedOperation::Update, $entries[2]->operation);
        $this->assertSame($cardId, $entries[2]->payload['committed_card_id']);
    }

    public function test_it_is_idempotent_when_retried_with_the_same_card_id_and_draft_content(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $cardId = strtolower((string) str()->ulid());

        $firstResult = app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, $cardId);
        $secondResult = app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, strtoupper($draft->id), strtoupper($cardId));

        $this->assertTrue($firstResult->wasCreated);
        $this->assertFalse($secondResult->wasCreated);
        $this->assertSame($cardId, $secondResult->card->id);
        $this->assertSame(1, Card::query()->count());
        // First commit emits deck, card, and draft update entries; retry emits none.
        $this->assertSame(3, SyncFeedEntry::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $cardId,
        ]);
    }

    public function test_it_rejects_duplicate_commits_with_a_different_card_id(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);
        $firstCardId = strtolower((string) str()->ulid());

        app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, $firstCardId);

        try {
            app(CreateStudyCardFromDraftAction::class)->handle(
                $draft->user_id,
                $draft->id,
                strtolower((string) str()->ulid()),
            );
            $this->fail('Expected a different card ID retry to be rejected.');
        } catch (StudyCardDraftConflictException $exception) {
            $this->assertSame('Draft was already committed with a different card ID.', $exception->getMessage());
        }

        $this->assertSame(1, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $draft->id,
            'committed_card_id' => $firstCardId,
        ]);
    }

    public function test_it_accepts_failed_drafts_after_the_user_has_corrected_the_content(): void
    {
        $draft = StudyCardDraft::factory()->failed()->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);

        $result = app(CreateStudyCardFromDraftAction::class)->handle(
            $draft->user_id,
            $draft->id,
            strtolower((string) str()->ulid()),
        );

        $this->assertTrue($result->wasCreated);
        $this->assertSame(CardType::Recognition, $result->card->card_type);
    }

    public function test_it_rejects_generating_drafts(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $this->expectException(StudyCardDraftConflictException::class);
        $this->expectExceptionMessage('Generating drafts cannot create cards yet.');

        app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, strtolower((string) str()->ulid()));
    }

    public function test_it_hides_cross_user_drafts_without_modifying_them(): void
    {
        $user = User::factory()->create();
        $otherDraft = StudyCardDraft::factory()->ready()->create();

        try {
            app(CreateStudyCardFromDraftAction::class)->handle($user->id, $otherDraft->id, strtolower((string) str()->ulid()));
            $this->fail('Expected cross-user drafts to be hidden.');
        } catch (StudyCardDraftNotFoundException $exception) {
            $this->assertSame('Study card draft not found.', $exception->getMessage());
        }

        $this->assertSame(0, Card::query()->count());
        $this->assertDatabaseHas('study_card_drafts', ['id' => $otherDraft->id]);
    }

    public function test_it_hides_missing_drafts(): void
    {
        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(CreateStudyCardFromDraftAction::class)->handle(
            User::factory()->create()->id,
            strtolower((string) str()->ulid()),
            strtolower((string) str()->ulid()),
        );
    }

    public function test_it_hides_malformed_draft_ids_without_querying_drafts(): void
    {
        $userId = User::factory()->create()->id;
        $cardId = strtolower((string) str()->ulid());

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(CreateStudyCardFromDraftAction::class)->handle($userId, 'not-a-ulid', $cardId);
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
        $this->assertSame(0, Card::query()->count());
        $this->assertSame(0, Deck::query()->count());
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_rejects_malformed_card_ids_without_querying_drafts_or_writing_side_effects(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['meaning' => 'back'],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, 'not-a-ulid');
            $this->fail('Expected malformed card IDs to be rejected.');
        } catch (CardValidationException $exception) {
            $this->assertSame('Card ID must be a valid ULID.', $exception->getMessage());
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_card_drafts')),
            'Malformed card IDs should be rejected before querying study_card_drafts.',
        );
        $this->assertSame(0, Card::query()->count());
        $this->assertSame(0, Deck::query()->count());
        $this->assertSame(0, SyncFeedEntry::query()->count());
        $this->assertNull($draft->refresh()->committed_card_id);
    }

    public function test_it_rejects_drafts_without_card_text(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueImage' => ['id' => 'image-1']],
            'answer_json' => ['answerImage' => ['id' => 'image-2']],
        ]);

        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, strtolower((string) str()->ulid()));
    }

    public function test_it_rejects_drafts_without_back_text(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create([
            'prompt_json' => ['cueText' => 'front'],
            'answer_json' => ['answerImage' => ['id' => 'image-2']],
        ]);

        $this->expectException(CardValidationException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(CreateStudyCardFromDraftAction::class)->handle($draft->user_id, $draft->id, strtolower((string) str()->ulid()));
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        app(CreateStudyCardFromDraftAction::class)->handle(
            $userId,
            strtolower((string) str()->ulid()),
            strtolower((string) str()->ulid()),
        );
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
}
