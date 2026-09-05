<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PrepareStudyCardDraftQueueSlotAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Study\Concerns\BuildsStudyCardDraftRows;
use Tests\TestCase;

class StoreStudyCardDraftCapacityApiTest extends TestCase
{
    use BuildsStudyCardDraftRows;
    use RefreshDatabase;

    public function test_it_returns_conflict_when_the_user_draft_queue_is_full(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $this->insertCappedDraftRowsFor($user);

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => [],
        ])
            ->assertConflict()
            ->assertJsonPath('message', 'Draft queue is full. Delete some drafts before adding more.');

        $this->assertDatabaseCount('study_card_drafts', PrepareStudyCardDraftQueueSlotAction::MAX_DRAFTS_PER_USER);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_not_found_when_the_authenticated_user_row_disappears_before_create(): void
    {
        Queue::fake();
        $user = $this->signIn();
        $user->delete();

        $this->postJson('/api/study/card-drafts', [
            'creationKind' => 'text-recognition',
            'cardType' => 'recognition',
            'prompt' => ['cueText' => '犬'],
            'answer' => [],
        ])->assertNotFound();

        $this->assertDatabaseCount('study_card_drafts', 0);
        $this->assertDatabaseCount('sync_feed_entries', 0);
        Queue::assertNothingPushed();
    }
}
