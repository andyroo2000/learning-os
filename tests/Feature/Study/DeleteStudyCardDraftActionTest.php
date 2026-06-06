<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Actions\DeleteStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\TestCase;

class DeleteStudyCardDraftActionTest extends TestCase
{
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_it_deletes_an_owned_study_card_draft(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        app(DeleteStudyCardDraftAction::class)->handle($draft->user_id, $draft->id);

        $this->assertDatabaseMissing('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    public function test_it_normalizes_uppercase_draft_ids_for_direct_callers(): void
    {
        $draft = StudyCardDraft::factory()->create();

        app(DeleteStudyCardDraftAction::class)->handle($draft->user_id, strtoupper($draft->id));

        $this->assertDatabaseMissing('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    public function test_it_noops_cross_user_drafts_without_deleting_them(): void
    {
        $user = User::factory()->create();
        $otherDraft = StudyCardDraft::factory()->create();

        app(DeleteStudyCardDraftAction::class)->handle($user->id, $otherDraft->id);

        $this->assertDatabaseHas('study_card_drafts', [
            'id' => $otherDraft->id,
        ]);
    }

    public function test_it_noops_missing_drafts(): void
    {
        $user = User::factory()->create();
        app(DeleteStudyCardDraftAction::class)->handle($user->id, strtolower((string) str()->ulid()));

        $this->assertDatabaseCount('study_card_drafts', 0);
    }

    public function test_it_noops_already_deleted_drafts(): void
    {
        $draft = StudyCardDraft::factory()->create();
        $draft->delete();

        app(DeleteStudyCardDraftAction::class)->handle($draft->user_id, $draft->id);

        $this->assertDatabaseMissing('study_card_drafts', [
            'id' => $draft->id,
        ]);
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        app(DeleteStudyCardDraftAction::class)->handle($userId, strtolower((string) str()->ulid()));
    }

    public function test_delete_relief_allows_create_after_the_full_queue_exception_is_cleared(): void
    {
        $user = User::factory()->create();
        $rows = $this->insertCappedDraftRowsFor($user);

        app(DeleteStudyCardDraftAction::class)->handle($user->id, $rows[0]['id']);

        $draft = app(CreateStudyCardDraftAction::class)->handle($this->createDraftDataFor($user));

        $this->assertSame($user->id, $draft->user_id);
        $this->assertDatabaseCount('study_card_drafts', CreateStudyCardDraftAction::MAX_DRAFTS_PER_USER);
    }

    private function createDraftDataFor(User $user): CreateStudyCardDraftData
    {
        return CreateStudyCardDraftData::fromInput(
            userId: $user->id,
            creationKind: StudyCardCreationKind::TextRecognition,
            cardType: CardType::Recognition,
            promptJson: ['cueText' => '犬'],
            answerJson: ['meaning' => 'dog'],
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
