<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Http\Middleware\TrimStrings;
use App\Http\Resources\Flashcards\CardResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpdateCardStudyStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_an_owned_card_study_status(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'due_at' => '2026-06-05T14:15:00Z',
        ]);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'suspended',
            'due_at' => '2030-01-01T00:00:00Z',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $card->id)
            ->assertJsonPath('data.study_status', 'suspended')
            ->assertJsonPath('data.new_queue_position', null)
            ->assertJsonPath('data.due_at', '2026-06-05T14:15:00.000000Z')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'deck_id',
                    'course_id',
                    'front_text',
                    'back_text',
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
            'study_status' => 'suspended',
            'due_at' => '2026-06-05 14:15:00',
        ]);

        $entry = SyncFeedEntry::query()->sole();

        $this->assertSame(SyncFeedOperation::Update, $entry->operation);
        $this->assertSame($card->id, $entry->resource_id);
        $this->assertSame('suspended', $entry->payload['study_status']);
        $this->assertNull($entry->payload['new_queue_position']);
    }

    public function test_it_normalizes_status_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => '  BURIED  ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.study_status', 'buried');
    }

    public function test_new_status_resets_study_schedule(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Relearning,
            'due_at' => '2026-06-05T14:15:00Z',
            'introduced_at' => '2026-06-01T14:15:00Z',
            'failed_at' => '2026-06-02T14:15:00Z',
            'last_reviewed_at' => '2026-06-03T14:15:00Z',
        ]);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'new',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.study_status', 'new')
            ->assertJsonPath('data.new_queue_position', 1)
            ->assertJsonPath('data.due_at', null)
            ->assertJsonPath('data.introduced_at', null)
            ->assertJsonPath('data.failed_at', null)
            ->assertJsonPath('data.last_reviewed_at', null);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'new',
            'new_queue_position' => 1,
            'due_at' => null,
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => null,
        ]);
    }

    public function test_it_is_idempotent_when_status_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $card = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Suspended,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'suspended',
        ]);

        $card->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', CardResource::make($card)->resolve()['updated_at']);

        $this->assertSame($timestamp->toJSON(), $card->updated_at?->toJSON());
        $this->assertDatabaseCount('sync_feed_entries', 0);
    }

    public function test_it_rejects_missing_status(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_blank_status_without_global_trim_middleware(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_malformed_status(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'queued',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_rejects_array_status(): void
    {
        $user = $this->signIn();
        $card = $this->cardFor($user);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => ['suspended'],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_status']);
    }

    public function test_it_hides_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
        ]);

        $response = $this->patchJson("/api/cards/{$otherCard->id}/study-status", [
            'study_status' => 'suspended',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $otherCard->id,
            'study_status' => 'review',
        ]);
    }

    public function test_it_authorizes_before_validating_another_users_card(): void
    {
        $this->signIn();
        $otherCard = Card::factory()->create();

        $response = $this->patchJson("/api/cards/{$otherCard->id}/study-status", [
            'study_status' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['study_status']);
    }

    public function test_it_returns_not_found_for_a_card_in_a_soft_deleted_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create([
            'study_status' => CardStudyStatus::Review,
        ]);

        $deck->delete();

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'suspended',
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'review',
        ]);
    }

    public function test_it_returns_not_found_for_a_missing_card(): void
    {
        $this->signIn();
        $missingCardId = (string) Str::ulid();

        $response = $this->patchJson("/api/cards/{$missingCardId}/study-status", [
            'study_status' => 'suspended',
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_card_id(): void
    {
        $this->signIn();

        $response = $this->patchJson('/api/cards/not-a-ulid/study-status', [
            'study_status' => 'suspended',
        ]);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $card = Card::factory()->create([
            'study_status' => CardStudyStatus::Review,
        ]);

        $response = $this->patchJson("/api/cards/{$card->id}/study-status", [
            'study_status' => 'suspended',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'study_status' => 'review',
        ]);
    }
}
