<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->study_status = CardStudyStatus::Review;
        $card->due_at = Carbon::parse('2026-06-05T14:15:00Z');
        $card->introduced_at = Carbon::parse('2026-06-01T14:15:00Z');
        $card->failed_at = Carbon::parse('2026-06-02T14:15:00Z');
        $card->last_reviewed_at = Carbon::parse('2026-06-03T14:15:00Z');
        $card->save();

        $response = $this->getJson("/api/cards/{$card->id}");

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $card->id,
                    'deck_id' => $card->deck_id,
                    'course_id' => null,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'study_status' => 'review',
                    'new_queue_position' => null,
                    'due_at' => '2026-06-05T14:15:00.000000Z',
                    'introduced_at' => '2026-06-01T14:15:00.000000Z',
                    'failed_at' => '2026-06-02T14:15:00.000000Z',
                    'last_reviewed_at' => '2026-06-03T14:15:00.000000Z',
                    'created_at' => $card->created_at->toJSON(),
                    'updated_at' => $card->updated_at->toJSON(),
                    'deleted_at' => null,
                ],
            ]);
    }

    public function test_it_shows_an_owned_card_with_an_uppercase_route_id(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->getJson('/api/cards/'.strtoupper($card->id));

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $otherCard = $this->cardFor($otherUser);

        $response = $this->getJson("/api/cards/{$otherCard->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards/'.(string) Str::ulid());

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        // The route ULID constraint rejects this before model binding.
        $response = $this->getJson('/api/cards/not-a-ulid');

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->getJson("/api/cards/{$card->id}");

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        // Deck soft deletes cascade to cards, so model binding excludes this card.
        $card->deck->delete();

        $response = $this->getJson("/api/cards/{$card->id}");

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = $this->cardFor(User::factory()->create());

        $response = $this->getJson("/api/cards/{$card->id}");

        $response->assertUnauthorized();
    }
}
