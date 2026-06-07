<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ShowStudyCardDraftAction;
use App\Domain\Study\Exceptions\StudyCardDraftNotFoundException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowStudyCardDraftActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_owned_study_card_draft(): void
    {
        $draft = StudyCardDraft::factory()->ready()->create();

        $result = app(ShowStudyCardDraftAction::class)->handle($draft->user_id, $draft->id);

        $this->assertSame($draft->id, $result->id);
        $this->assertSame($draft->user_id, $result->user_id);
    }

    public function test_it_normalizes_uppercase_draft_ids_for_direct_callers(): void
    {
        $draft = StudyCardDraft::factory()->create();

        $result = app(ShowStudyCardDraftAction::class)->handle($draft->user_id, strtoupper($draft->id));

        $this->assertSame($draft->id, $result->id);
    }

    public function test_it_hides_cross_user_drafts_without_modifying_them(): void
    {
        $user = User::factory()->create();
        $otherDraft = StudyCardDraft::factory()->create();

        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        try {
            app(ShowStudyCardDraftAction::class)->handle($user->id, $otherDraft->id);
        } finally {
            $this->assertDatabaseHas('study_card_drafts', [
                'id' => $otherDraft->id,
            ]);
        }
    }

    public function test_it_hides_missing_drafts(): void
    {
        $this->expectException(StudyCardDraftNotFoundException::class);
        $this->expectExceptionMessage('Study card draft not found.');

        app(ShowStudyCardDraftAction::class)->handle(
            User::factory()->create()->id,
            strtolower((string) str()->ulid()),
        );
    }

    public function test_it_hides_malformed_draft_ids_without_querying_drafts(): void
    {
        $userId = User::factory()->create()->id;

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            app(ShowStudyCardDraftAction::class)->handle($userId, 'not-a-ulid');
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
    }

    #[DataProvider('nonPositiveUserIdProvider')]
    public function test_it_rejects_non_positive_user_ids_for_direct_callers(int $userId): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study card draft user ID must be a positive integer.');

        app(ShowStudyCardDraftAction::class)->handle($userId, strtolower((string) str()->ulid()));
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
