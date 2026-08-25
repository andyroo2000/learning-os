<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\LinkCardLearningPathSuccessorAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class CardLearningPathApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication_for_showing_and_linking_paths(): void
    {
        $cardId = strtolower((string) str()->ulid());

        $this->getJson("/api/cards/{$cardId}/learning-path")
            ->assertUnauthorized();
        $this->putJson("/api/cards/{$cardId}/learning-path/successor", [
            'successor_card_id' => strtolower((string) str()->ulid()),
        ])->assertUnauthorized();
    }

    public function test_it_validates_the_successor_identifier(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $this->putJson("/api/cards/{$card->id}/learning-path/successor", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['successor_card_id']);
        $this->putJson("/api/cards/{$card->id}/learning-path/successor", [
            'successor_card_id' => 'not-a-ulid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['successor_card_id']);
    }

    public function test_it_creates_and_lists_a_generic_learning_path(): void
    {
        Carbon::setTestNow('2026-08-25T12:00:00Z');

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            $predecessor = Card::factory()->for($deck)->create([
                'new_queue_position' => null,
            ]);
            $successor = Card::factory()->for($deck)->create([
                'new_queue_position' => 8,
            ]);

            $response = $this->putJson("/api/cards/{$predecessor->id}/learning-path/successor", [
                'successor_card_id' => strtoupper($successor->id),
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('data.anchor_card_id', $predecessor->id)
                ->assertJsonPath('data.stages.0.number', 1)
                ->assertJsonPath('data.stages.0.cards.0.id', $predecessor->id)
                ->assertJsonPath('data.stages.1.number', 2)
                ->assertJsonPath('data.stages.1.cards.0.id', $successor->id);

            $groupId = $response->json('data.group_id');
            $this->assertIsString($groupId);
            $this->assertSame(26, strlen($groupId));

            $this->assertDatabaseHas('cards', [
                'id' => $predecessor->id,
                'variant_group_id' => $groupId,
                'variant_stage' => 1,
                'variant_status' => VocabVariantStatus::Available->value,
                'variant_unlocked_at' => '2026-08-25 12:00:00',
                'new_queue_position' => 1,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $successor->id,
                'variant_group_id' => $groupId,
                'variant_stage' => 2,
                'variant_status' => VocabVariantStatus::Locked->value,
                'variant_unlocked_at' => null,
                'new_queue_position' => null,
            ]);
            $this->assertSame(2, SyncFeedEntry::query()->where('user_id', $user->id)->count());

            $otherUser = User::factory()->create();
            Card::factory()->for($this->deckFor($otherUser))->create([
                'variant_group_id' => $groupId,
                'variant_stage' => 3,
                'variant_status' => VocabVariantStatus::Locked->value,
            ]);
            $deletedDeck = $this->deckFor($user);
            Card::factory()->for($deletedDeck)->create([
                'variant_group_id' => $groupId,
                'variant_stage' => 3,
                'variant_status' => VocabVariantStatus::Locked->value,
            ]);
            $deletedDeck->delete();

            $this->getJson("/api/cards/{$successor->id}/learning-path")
                ->assertOk()
                ->assertJsonPath('data.group_id', $groupId)
                ->assertJsonPath('data.anchor_card_id', $successor->id)
                ->assertJsonCount(2, 'data.stages');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_an_ungrouped_card_has_an_empty_learning_path(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create();

        $this->getJson("/api/cards/{$card->id}/learning-path")
            ->assertOk()
            ->assertJsonPath('data.group_id', null)
            ->assertJsonPath('data.anchor_card_id', $card->id)
            ->assertJsonCount(0, 'data.stages');
    }

    public function test_it_extends_only_the_tail_and_exact_retries_are_no_ops(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $first = Card::factory()->for($deck)->create();
        $second = Card::factory()->for($deck)->create();
        $third = Card::factory()->for($deck)->create();
        $other = Card::factory()->for($deck)->create();

        $this->link($first, $second)->assertOk();
        $this->link($second, $third)
            ->assertOk()
            ->assertJsonCount(3, 'data.stages')
            ->assertJsonPath('data.stages.2.cards.0.id', $third->id);

        $syncCount = SyncFeedEntry::query()->where('user_id', $user->id)->count();

        $this->link($second, $third)
            ->assertOk()
            ->assertJsonCount(3, 'data.stages');
        $this->assertSame($syncCount, SyncFeedEntry::query()->where('user_id', $user->id)->count());

        $this->link($first, $other)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_predecessor_not_tail');
        $this->assertNull($other->refresh()->variant_group_id);
    }

    public function test_it_extends_a_generated_family_with_multiple_cards_in_its_tail_stage(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $groupId = 'generated-multi-card-tail';
        Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition->value,
            'variant_sentence_id' => 'generated-sentence-1',
        ]);
        $firstTailCard = Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked->value,
            'variant_kind' => VocabVariantKind::SentenceTextRecognition->value,
            'variant_sentence_id' => 'generated-sentence-1',
        ]);
        $secondTailCard = Card::factory()->for($deck)->create([
            'variant_group_id' => $groupId,
            'variant_stage' => 2,
            'variant_status' => VocabVariantStatus::Locked->value,
            'variant_kind' => VocabVariantKind::SentenceTextRecognition->value,
            'variant_sentence_id' => 'generated-sentence-2',
        ]);
        $successor = Card::factory()->for($deck)->create();

        $this->link($firstTailCard, $successor)
            ->assertOk()
            ->assertJsonPath('data.group_id', $groupId)
            ->assertJsonCount(3, 'data.stages')
            ->assertJsonCount(2, 'data.stages.1.cards')
            ->assertJsonPath('data.stages.2.number', 3)
            ->assertJsonPath('data.stages.2.cards.0.id', $successor->id);

        $this->assertSame(VocabVariantStatus::Locked->value, $firstTailCard->refresh()->variant_status);
        $this->assertSame('generated-sentence-1', $firstTailCard->variant_sentence_id);
        $this->assertSame(VocabVariantStatus::Locked->value, $secondTailCard->refresh()->variant_status);
        $this->assertSame('generated-sentence-2', $secondTailCard->variant_sentence_id);
        $this->assertSame($groupId, $successor->refresh()->variant_group_id);
        $this->assertSame(3, $successor->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $successor->variant_status);
    }

    public function test_it_rejects_self_links_existing_memberships_and_partial_metadata(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $first = Card::factory()->for($deck)->create();
        $second = Card::factory()->for($deck)->create();
        $otherFirst = Card::factory()->for($deck)->create();
        $otherSecond = Card::factory()->for($deck)->create();

        $this->link($first, $first)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_same_card');

        $this->link($first, $second)->assertOk();
        $this->link($otherFirst, $otherSecond)->assertOk();
        $this->link($second, $otherSecond)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_successor_already_linked');

        $partialPredecessor = Card::factory()->for($deck)->create([
            'variant_group_id' => 'partial-predecessor',
        ]);
        $availableTarget = Card::factory()->for($deck)->create();

        $this->link($partialPredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_invalid_predecessor');

        $orphanedKindPredecessor = Card::factory()->for($deck)->create([
            'variant_kind' => VocabVariantKind::WordTextRecognition->value,
        ]);

        $this->link($orphanedKindPredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_invalid_predecessor');

        $orphanedSentenceSuccessor = Card::factory()->for($deck)->create([
            'variant_sentence_id' => 'orphaned-sentence',
        ]);

        $this->link($availableTarget, $orphanedSentenceSuccessor)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_successor_already_linked');

        $invalidFamilyPredecessor = Card::factory()->for($deck)->create([
            'variant_group_id' => 'partial-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => 'partial-family',
            'variant_stage' => null,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->link($invalidFamilyPredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_invalid_family');

        $mixedStagePredecessor = Card::factory()->for($deck)->create([
            'variant_group_id' => 'mixed-stage-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);
        Card::factory()->for($deck)->create([
            'variant_group_id' => 'mixed-stage-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->link($mixedStagePredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_invalid_family');

        $allLockedPredecessor = Card::factory()->for($deck)->create([
            'variant_group_id' => 'all-locked-family',
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Locked->value,
        ]);

        $this->link($allLockedPredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_invalid_family');

        $stageLimitPredecessor = Card::factory()->for($deck)->create([
            'variant_group_id' => 'stage-limit-family',
            'variant_stage' => Card::MAX_VARIANT_STAGE,
            'variant_status' => VocabVariantStatus::Available->value,
        ]);

        $this->link($stageLimitPredecessor, $availableTarget)
            ->assertConflict()
            ->assertJsonPath('reason', 'learning_path_stage_limit');
    }

    public function test_it_hides_missing_cross_owner_and_deleted_deck_successors(): void
    {
        $user = $this->signIn();
        $predecessor = Card::factory()->for($this->deckFor($user))->create();
        $otherUser = User::factory()->create();
        $otherCard = Card::factory()->for($this->deckFor($otherUser))->create();
        $deletedDeckCard = Card::factory()->for($this->deckFor($user))->create();
        $deletedDeckCard->deck->delete();

        $this->linkId($predecessor, strtolower((string) str()->ulid()))
            ->assertNotFound();
        $this->link($predecessor, $otherCard)
            ->assertNotFound();
        $this->link($predecessor, $deletedDeckCard)
            ->assertNotFound();

        $this->assertNull($predecessor->refresh()->variant_group_id);
    }

    public function test_linking_sets_a_new_retrieval_boundary_for_existing_review_history(): void
    {
        Carbon::setTestNow('2026-08-25T12:00:00Z');

        try {
            $user = $this->signIn();
            $deck = $this->deckFor($user);
            $predecessor = Card::factory()->for($deck)->create();
            $successor = Card::factory()->for($deck)->create();

            $this->review($predecessor, CardReviewRating::Good, '2026-08-25T11:00:00Z');
            $this->review($predecessor, CardReviewRating::Easy, '2026-08-25T11:05:00Z');
            $this->link($predecessor, $successor)->assertOk();

            $this->review($predecessor, CardReviewRating::Good, '2026-08-25T12:05:00Z');
            $this->assertSame(VocabVariantStatus::Locked->value, $successor->refresh()->variant_status);

            $this->review($predecessor, CardReviewRating::Easy, '2026-08-25T12:10:00Z');
            $this->assertSame(VocabVariantStatus::Available->value, $successor->refresh()->variant_status);
            $this->assertSame('2026-08-25T12:10:00.000000Z', $successor->variant_unlocked_at?->toJSON());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_path_and_sync_writes_roll_back_together(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $predecessor = Card::factory()->for($deck)->create();
        $successor = Card::factory()->for($deck)->create();
        $recordSyncFeedEntry = new class($successor->id) extends RecordSyncFeedEntryAction
        {
            public function __construct(private readonly string $failingCardId) {}

            public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
            {
                if ($data->resourceId === $this->failingCardId) {
                    throw new RuntimeException('Learning path sync failed.');
                }

                return parent::handle($data);
            }
        };

        try {
            (new LinkCardLearningPathSuccessorAction($recordSyncFeedEntry))->handle($predecessor, $successor);
            $this->fail('Expected sync failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Learning path sync failed.', $exception->getMessage());
        }

        $this->assertNull($predecessor->refresh()->variant_group_id);
        $this->assertNull($successor->refresh()->variant_group_id);
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_direct_action_hides_a_cross_owner_successor(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $predecessor = Card::factory()->for($this->deckFor($firstUser))->create();
        $successor = Card::factory()->for($this->deckFor($secondUser))->create();

        $this->expectException(ModelNotFoundException::class);

        app(LinkCardLearningPathSuccessorAction::class)->handle($predecessor, $successor);
    }

    private function link(Card $predecessor, Card $successor)
    {
        return $this->linkId($predecessor, $successor->id);
    }

    private function linkId(Card $predecessor, string $successorId)
    {
        return $this->putJson("/api/cards/{$predecessor->id}/learning-path/successor", [
            'successor_card_id' => $successorId,
        ]);
    }

    private function review(Card $card, CardReviewRating $rating, string $reviewedAt): void
    {
        app(ReviewCardAction::class)->handle(ReviewCardData::fromInput(
            cardId: $card->id,
            rating: $rating->value,
            reviewedAt: $reviewedAt,
        ));
    }
}
