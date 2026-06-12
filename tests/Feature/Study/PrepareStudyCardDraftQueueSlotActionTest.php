<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PrepareStudyCardDraftQueueSlotAction;
use App\Domain\Study\Exceptions\StudyCardDraftConflictException;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\TestCase;

class PrepareStudyCardDraftQueueSlotActionTest extends TestCase
{
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_it_allows_a_user_below_the_draft_queue_cap(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->insertCappedDraftRowsFor($otherUser);

        DB::transaction(
            fn () => app(PrepareStudyCardDraftQueueSlotAction::class)->handle($user->id),
        );

        $this->assertSame(
            PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER,
            StudyCardDraft::query()->where('user_id', $otherUser->id)->count(),
        );
        $this->assertSame(0, StudyCardDraft::query()->where('user_id', $user->id)->count());
    }

    public function test_it_rejects_a_user_at_the_draft_queue_cap(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->insertCappedDraftRowsFor($user);

        StudyCardDraft::factory()->for($otherUser)->create();

        try {
            DB::transaction(
                fn () => app(PrepareStudyCardDraftQueueSlotAction::class)->handle($user->id),
            );

            $this->fail('Expected queue full conflict.');
        } catch (StudyCardDraftConflictException $exception) {
            $this->assertSame('Draft queue is full. Delete some drafts before adding more.', $exception->getMessage());
        }

        $this->assertSame(
            PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER,
            StudyCardDraft::query()->where('user_id', $user->id)->count(),
        );
        $this->assertSame(1, StudyCardDraft::query()->where('user_id', $otherUser->id)->count());
    }

    public function test_it_locks_the_owner_before_counting_existing_drafts(): void
    {
        $user = User::factory()->create();
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        DB::transaction(
            fn () => app(PrepareStudyCardDraftQueueSlotAction::class)->handle($user->id),
        );

        $usersQueryIndex = $this->firstQueryIndexContaining($queries, 'from "users"');
        $draftCountQueryIndex = $this->firstQueryIndexContaining($queries, 'from "study_card_drafts"');

        $this->assertNotNull($usersQueryIndex, 'Expected the queue-slot guard to query the owning user.');
        $this->assertNotNull($draftCountQueryIndex, 'Expected the queue-slot guard to count existing drafts.');
        $this->assertLessThan(
            $draftCountQueryIndex,
            $usersQueryIndex,
            'The owner row must be locked before the capped draft count is read.',
        );
    }

    public function test_it_rejects_missing_users_before_counting_drafts(): void
    {
        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $caughtException = null;

        try {
            DB::transaction(
                fn () => app(PrepareStudyCardDraftQueueSlotAction::class)->handle(123456),
            );
        } catch (ModelNotFoundException $exception) {
            $caughtException = $exception;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $caughtException);
        $this->assertNotNull(
            $this->firstQueryIndexContaining($queries, 'from "users"'),
            'Expected the queue-slot guard to query the owning user.',
        );
        $this->assertNull(
            $this->firstQueryIndexContaining($queries, 'from "study_card_drafts"'),
            'Missing users should fail before draft counts or other side effects.',
        );
    }

    /**
     * @param  list<string>  $queries
     */
    private function firstQueryIndexContaining(array $queries, string $needle): ?int
    {
        foreach ($queries as $index => $query) {
            if (str_contains($query, $needle)) {
                return $index;
            }
        }

        return null;
    }
}
