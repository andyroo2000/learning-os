<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateCardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_an_owned_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.deck_id', $card->deck_id)
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye')
            ->assertJsonPath('data.card_type', 'recognition')
            ->assertJsonMissingPath('data.media_assets')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'course_id',
                    'front_text',
                    'back_text',
                    'card_type',
                    'study_status',
                    'new_queue_position',
                    'scheduler_state',
                    'due_at',
                    'introduced_at',
                    'failed_at',
                    'last_reviewed_at',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_normalizes_text_inputs(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  arrivederci  ',
            'back_text' => '  goodbye  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.front_text', 'arrivederci')
            ->assertJsonPath('data.back_text', 'goodbye');
    }

    public function test_it_ignores_client_provided_study_state(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'study_status' => 'review',
            'new_queue_position' => 99,
            'scheduler_state' => ['state' => 2],
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.study_status', 'new')
            ->assertJsonPath('data.new_queue_position', $card->new_queue_position)
            ->assertJsonPath('data.scheduler_state', null)
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.introduced_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.last_reviewed_at', null);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'new',
            'new_queue_position' => $card->new_queue_position,
            'scheduler_state' => null,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    public function test_it_updates_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => ' CLOZE ',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.card_type', 'cloze');

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'cloze',
        ]);
    }

    public function test_it_is_idempotent_when_text_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_trimmed_text_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_updates_timestamp_when_text_changes(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertTrue($card->updated_at->isAfter($timestamp));
        $this->assertNotSame($timestamp->toJSON(), $response->json('data.updated_at'));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => 'goodbye',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_text_fields_together(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_non_string_text_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => ['arrivederci'],
            'back_text' => ['goodbye'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_blank_card_type_without_global_trim_middleware(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->putJson("/api/cards/{$card->id}", [
                'front_text' => 'arrivederci',
                'back_text' => 'goodbye',
                'card_type' => '   ',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_malformed_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => 'reverse',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_null_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => null,
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_array_card_type(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
            'card_type' => ['cloze'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['card_type']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'recognition',
        ]);
    }

    public function test_it_rejects_missing_required_fields(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->putJson("/api/cards/{$card->id}", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text', 'back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_rejects_partial_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $missingFrontText = $this->putJson("/api/cards/{$card->id}", [
            'back_text' => 'goodbye',
        ]);

        $missingFrontText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['front_text']);

        $missingBackText = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
        ]);

        $missingBackText
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back_text']);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherCard->id,
            'front_text' => $otherCard->front_text,
            'back_text' => $otherCard->back_text,
        ]);
    }

    public function test_it_authorizes_before_validating_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$otherCard->id}", [
            'front_text' => '   ',
            'back_text' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['front_text', 'back_text']);
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create();

        $deck->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_soft_deleted_card(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $card->delete();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = (string) Str::ulid();

        $response = $this->putJson("/api/cards/{$missingCardId}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/cards/not-a-ulid', [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create();

        $response = $this->putJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }

    public function test_it_does_not_accept_patch_updates(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}", [
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $response->assertStatus(405);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
        ]);
    }
}
