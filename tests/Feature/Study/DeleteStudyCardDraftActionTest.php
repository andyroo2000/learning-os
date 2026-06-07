<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Actions\CreateStudyCardDraftAction;
use App\Domain\Study\Actions\DeleteStudyCardDraftAction;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $entry = SyncFeedEntry::query()->sole();
        $this->assertSame($draft->user_id, $entry->user_id);
        $this->assertSame('study', $entry->domain);
        $this->assertSame('study_card_draft', $entry->resource_type);
        $this->assertSame($draft->id, $entry->resource_id);
        $this->assertSame(SyncFeedOperation::Delete, $entry->operation);
        $this->assertSame($draft->id, $entry->payload['id']);
        $this->assertSame($draft->status->value, $entry->payload['status']);
        $this->assertJsonTimestamp($entry->payload['deleted_at']);
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
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_noops_missing_drafts(): void
    {
        $user = User::factory()->create();
        app(DeleteStudyCardDraftAction::class)->handle($user->id, strtolower((string) str()->ulid()));

        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertSame(0, SyncFeedEntry::query()->count());
    }

    public function test_it_noops_malformed_draft_ids_without_querying_drafts(): void
    {
        $userId = User::factory()->create()->id;

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(DeleteStudyCardDraftAction::class)->handle($userId, 'not-a-ulid');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_card_drafts')),
            'Malformed draft IDs should no-op before querying study_card_drafts.',
        );
        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertSame(0, SyncFeedEntry::query()->count());
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
