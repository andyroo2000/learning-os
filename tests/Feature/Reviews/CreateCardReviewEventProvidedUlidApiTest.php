<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Str;

class CreateCardReviewEventProvidedUlidApiTest extends CreateCardReviewEventApiTestCase
{
    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this->postJson('/api/card-review-events', [
            'id' => $id,
            'card_id' => $card->id,
            'rating' => CardReviewRating::Easy->value,
            'reviewed_at' => '2026-05-27T09:15:00Z',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_normalizes_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => '  '.strtoupper($id).'  ',
                'card_id' => '  '.strtoupper($card->id).'  ',
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_trims_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => "  {$id}  ",
                'card_id' => "  {$card->id}  ",
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }

    public function test_it_lowercases_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events', [
                'id' => strtoupper($id),
                'card_id' => strtoupper($card->id),
                'rating' => CardReviewRating::Easy->value,
                'reviewed_at' => '2026-05-27T09:15:00Z',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
        ]);
    }
}
