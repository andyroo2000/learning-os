<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Enums\CardReviewRating;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateCardReviewEventBatchMetadataNormalizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_trims_client_sync_metadata_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'card_id' => $card->id,
                        'rating' => '  good  ',
                        'reviewed_at' => '  2026-05-27T09:15:00Z  ',
                        'client_event_id' => '  event-123  ',
                        'device_id' => '  device-abc  ',
                        'client_created_at' => '  2026-05-27T09:14:00Z  ',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.rating', CardReviewRating::Good->value)
            ->assertJsonPath('data.0.reviewed_at', '2026-05-27T09:15:00.000000Z')
            ->assertJsonPath('data.0.client_event_id', 'event-123')
            ->assertJsonPath('data.0.device_id', 'device-abc')
            ->assertJsonPath('data.0.client_created_at', '2026-05-27T09:14:00.000000Z');

        $this->assertDatabaseHas('card_review_events', [
            'id' => $response->json('data.0.id'),
            'rating' => CardReviewRating::Good->value,
            'reviewed_at' => '2026-05-27 09:15:00',
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
            'client_created_at' => '2026-05-27 09:14:00',
        ]);
    }

    public function test_it_trims_client_provided_ulids_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);
        $id = strtolower((string) Str::ulid());

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/card-review-events/batch', [
                'events' => [
                    [
                        'id' => "  {$id}  ",
                        'card_id' => "  {$card->id}  ",
                        'rating' => CardReviewRating::Good->value,
                        'reviewed_at' => '2026-05-27T09:15:00Z',
                        'client_event_id' => 'event-123',
                        'device_id' => 'device-abc',
                        'client_created_at' => '2026-05-27T09:14:00Z',
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.card_id', $card->id);

        $this->assertDatabaseHas('card_review_events', [
            'id' => $id,
            'card_id' => $card->id,
            'client_event_id' => 'event-123',
            'device_id' => 'device-abc',
        ]);
    }
}
