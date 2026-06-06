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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);
        $cardId = strtolower((string) str()->ulid());

        $result = app(CreateStudyCardFromDraftAction::class)->handle($user->id, $draft->id, $cardId);

        $this->assertTrue($result->wasCreated);
        $this->assertSame($cardId, $result->card->id);
        $this->assertSame(CardType::Production, $result->card->card_type);
        $this->assertSame(['cueText' => '会社'], $result->card->prompt_json);
        $this->assertSame(['meaning' => 'company'], $result->card->answer_json);
        $this->assertSame('会社', $result->card->front_text);
        $this->assertSame('company', $result->card->back_text);
        $this->assertDatabaseHas('study_card_drafts', ['id' => $draft->id]);

        $deck = Deck::query()->sole();
        $this->assertSame($user->id, $deck->user_id);
        $this->assertSame(ResolveManualStudyDeckAction::DEFAULT_DECK_NAME, $deck->name);
        $this->assertTrue($deck->is_manual_study_deck);
        $this->assertSame($deck->id, $result->card->deck_id);

        $entries = SyncFeedEntry::query()->orderBy('checkpoint')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('deck', $entries[0]->resource_type);
        $this->assertSame(SyncFeedOperation::Create, $entries[0]->operation);
        $this->assertSame('card', $entries[1]->resource_type);
        $this->assertSame($cardId, $entries[1]->resource_id);
        $this->assertSame(SyncFeedOperation::Create, $entries[1]->operation);
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
        $this->assertSame(2, SyncFeedEntry::query()->count());
        $this->assertDatabaseHas('study_card_drafts', ['id' => $draft->id]);
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

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        try {
            app(CreateStudyCardFromDraftAction::class)->handle($user->id, $otherDraft->id, strtolower((string) str()->ulid()));
        } finally {
            $this->assertSame(0, Card::query()->count());
            $this->assertDatabaseHas('study_card_drafts', ['id' => $otherDraft->id]);
        }
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
