<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Flashcards\Concerns\UsesStudyCardRateLimitOverrides;
use Tests\TestCase;

class DeleteCardApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesStudyCardRateLimitOverrides;

    public function test_it_deletes_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $mediaAsset = MediaAsset::factory()->create();
        $reviewEvent = CardReviewEvent::factory()->for($card)->create();

        $card->mediaAssets()->attach($mediaAsset->id);

        $response = $this->deleteJson("/api/cards/{$card->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
        $this->assertDatabaseHas('card_media', [
            'card_id' => $card->id,
            'media_asset_id' => $mediaAsset->id,
        ]);
        $this->assertDatabaseHas('card_review_events', [
            'id' => $reviewEvent->id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->deleteJson("/api/cards/{$otherCard->id}");

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherCard->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_is_idempotent_for_a_card_cascade_deleted_with_its_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        $deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $response = $this->deleteJson("/api/cards/{$card->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_card_hard_deleted_with_its_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();
        $cardId = $card->id;

        $deck->forceDelete();

        $this->assertDatabaseMissing('cards', [
            'id' => $cardId,
        ]);

        $response = $this->deleteJson("/api/cards/{$cardId}");

        $response->assertNotFound();
    }

    public function test_it_is_idempotent_for_an_already_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->deleteJson("/api/cards/{$card->id}");

        $response->assertNoContent();
    }

    public function test_delete_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $cards = Card::factory()->count(3)->for($this->deckFor($user))->create();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $this->withStudyCardRateLimitOverride(
            StudyCardDeleteRateLimiter::NAME,
            [$user->id, $otherUser->id],
            function () use ($cards, $otherCard, $otherUser, $user): void {
                foreach ($cards->take(2) as $card) {
                    $this
                        ->deleteJson("/api/cards/{$card->id}")
                        ->assertNoContent();
                }

                $this->signIn($otherUser);

                $this
                    ->deleteJson("/api/cards/{$otherCard->id}")
                    ->assertNoContent();

                $this->signIn($user);

                $blockedCard = $cards->last();

                $this
                    ->deleteJson("/api/cards/{$blockedCard->id}")
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/cards/{$blockedCard->id}")
                    ->assertOk()
                    ->assertJsonPath('data.id', $blockedCard->id);

                $this->assertSoftDeleted('cards', ['id' => $cards[0]->id]);
                $this->assertSoftDeleted('cards', ['id' => $cards[1]->id]);
                $this->assertSoftDeleted('cards', ['id' => $otherCard->id]);
                $this->assertDatabaseHas('cards', [
                    'id' => $blockedCard->id,
                    'deleted_at' => null,
                ]);
            },
        );
    }

    public function test_it_hides_another_users_soft_deleted_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $otherCard->delete();

        $response = $this->deleteJson("/api/cards/{$otherCard->id}");

        $response->assertNotFound();
    }

    public function test_it_hides_another_users_card_cascade_deleted_with_its_deck(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $otherCard->deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $otherCard->id,
        ]);

        $response = $this->deleteJson("/api/cards/{$otherCard->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/cards/'.((string) Str::ulid()));

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->deleteJson('/api/cards/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->deleteJson("/api/cards/{$card->id}");

        $response->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_requires_authentication_for_a_soft_deleted_card(): void
    {
        $card = Card::factory()->create();

        $card->delete();

        $response = $this->deleteJson("/api/cards/{$card->id}");

        $response->assertUnauthorized();
    }
}
