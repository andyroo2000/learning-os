<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SetsCardStudyStatus;
use Tests\TestCase;

class ListCardsStudyStatusFilterApiTest extends TestCase
{
    use RefreshDatabase;
    use SetsCardStudyStatus;

    public function test_it_filters_cards_by_study_status(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);
        $otherUserCard = $this->cardWithStudyStatus(
            $this->deckFor(User::factory()->create()),
            CardStudyStatus::Review,
        );

        $response = $this->getJson('/api/cards?study_status=review');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewCard->id)
            ->assertJsonPath('data.0.study_status', 'review')
            ->assertJsonMissing([
                'id' => $newCard->id,
            ])
            ->assertJsonMissing([
                'id' => $otherUserCard->id,
            ]);
    }

    public function test_it_normalizes_study_status_filters_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $reviewCard = $this->cardWithStudyStatus($deck, CardStudyStatus::Review);
        $newCard = $this->cardWithStudyStatus($deck, CardStudyStatus::New);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?study_status=%20REVIEW%20');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $reviewCard->id)
            ->assertJsonMissing([
                'id' => $newCard->id,
            ]);
    }

    public function test_it_rejects_a_blank_study_status_filter_without_global_trim_middleware(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/cards?study_status=%20%20%20');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_a_malformed_study_status_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?study_status=queued');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_an_array_study_status_filter(): void
    {
        $this->signIn();

        $response = $this->getJson('/api/cards?study_status[]=review');

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }
}
