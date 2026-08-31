<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class PromoteStudyNewCardQueueApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/study/new-queue/'.strtolower((string) str()->ulid()).'/promote')
            ->assertUnauthorized();
    }

    public function test_it_promotes_the_card_and_returns_the_canonical_first_page(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $targetCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 2,
        ]);

        $this->postJson('/api/study/new-queue/'.strtoupper($targetCard->id).'/promote')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('limit', 100)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.id', $targetCard->id)
            ->assertJsonPath('items.0.queuePosition', 0)
            ->assertJsonPath('items.1.id', $firstCard->id)
            ->assertJsonPath('items.1.queuePosition', 1);

        $this->assertDatabaseCount('sync_feed_entries', 1);
    }

    public function test_it_is_retry_safe_when_the_card_is_already_first(): void
    {
        $user = $this->signIn();
        $card = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
            ['new_queue_position' => 1],
        );
        $this->assertNotNull($card->updated_at);
        $updatedAt = $card->updated_at->toJSON();

        $this->postJson("/api/study/new-queue/{$card->id}/promote")
            ->assertOk()
            ->assertJsonPath('items.0.id', $card->id)
            ->assertJsonPath('items.0.queuePosition', 1);

        $this->postJson("/api/study/new-queue/{$card->id}/promote")
            ->assertOk()
            ->assertJsonPath('items.0.id', $card->id)
            ->assertJsonPath('items.0.queuePosition', 1);

        $this->assertNotNull($card->refresh()->updated_at);
        $this->assertSame($updatedAt, $card->updated_at->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_accepts_a_copied_card_convolab_identifier(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $this->cardWithStudyStatus($deck, CardStudyStatus::New, [
            'new_queue_position' => 1,
        ]);
        $targetCard = Card::factory()->for($deck)->make([
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => 2,
        ]);
        $targetCard->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $targetCard->save();

        $this->postJson('/api/study/new-queue/C358732A-2CD0-4B18-9CCE-C474297863F9/promote')
            ->assertOk()
            ->assertJsonPath('items.0.id', $targetCard->id)
            ->assertJsonPath('items.0.queuePosition', 0);
    }

    public function test_it_hides_missing_cross_user_deleted_and_ineligible_cards_behind_not_found(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $crossUserCard = $this->cardWithStudyStatus(
            $this->deckFor($otherUser),
            CardStudyStatus::New,
            ['new_queue_position' => 1],
        );
        $deletedCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
            ['new_queue_position' => 1],
        );
        $deletedCard->delete();
        $deletedDeck = $this->deckFor($user);
        $deletedDeckCard = $this->cardWithStudyStatus(
            $deletedDeck,
            CardStudyStatus::New,
            ['new_queue_position' => 2],
        );
        $deletedDeck->delete();
        $reviewCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::Review,
        );
        $lockedCard = $this->cardWithStudyStatus(
            $this->deckFor($user),
            CardStudyStatus::New,
            [
                'new_queue_position' => 2,
                'variant_status' => VocabVariantStatus::Locked->value,
            ],
        );

        foreach ([
            strtolower((string) str()->ulid()),
            $crossUserCard->id,
            $deletedCard->id,
            $deletedDeckCard->id,
            $reviewCard->id,
            $lockedCard->id,
        ] as $cardId) {
            $this->postJson("/api/study/new-queue/{$cardId}/promote")
                ->assertNotFound();
        }

        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_malformed_route_ids_without_exposing_the_endpoint(): void
    {
        $this->signIn();

        $this->postJson('/api/study/new-queue/not-a-card-id/promote')
            ->assertNotFound();
    }
}
