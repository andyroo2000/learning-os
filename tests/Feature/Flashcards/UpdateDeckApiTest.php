<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Flashcards\Concerns\UsesDeckRateLimitOverrides;
use Tests\TestCase;

class UpdateDeckApiTest extends TestCase
{
    use RefreshDatabase;
    use UsesDeckRateLimitOverrides;

    public function test_it_updates_an_owned_deck(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $deck->id)
            ->assertJsonPath('data.name', 'Italian Travel')
            ->assertJsonPath('data.description', 'Phrases for airport and train station practice.')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'user_id' => $user->id,
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);
    }

    public function test_it_normalizes_optional_description(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => '  Italian Travel  ',
            'description' => '   ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Italian Travel')
            ->assertJsonPath('data.description', null);
    }

    public function test_it_stores_empty_description_as_null(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Travel',
            'description' => '',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'description' => null,
        ]);
    }

    public function test_it_clears_description_when_null_is_sent(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'description' => null,
        ]);
    }

    public function test_it_is_idempotent_when_metadata_is_unchanged(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $deck->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', DeckResource::make($deck)->resolve()['updated_at']);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_is_idempotent_when_trimmed_metadata_matches_existing_values(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => '  Italian Basics  ',
            'description' => '  Foundational Italian review cards.  ',
        ]);

        $deck->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', DeckResource::make($deck)->resolve()['updated_at']);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'updated_at' => $timestamp,
        ]);
    }

    public function test_it_updates_timestamp_when_metadata_changes(): void
    {
        $user = $this->signIn();
        $timestamp = now()->subDay()->startOfSecond();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);

        $deck->refresh();

        $response
            ->assertOk()
            ->assertJsonPath('data.updated_at', DeckResource::make($deck)->resolve()['updated_at']);

        $this->assertTrue($deck->updated_at->isAfter($timestamp));

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => 'Italian Travel',
            'description' => 'Phrases for airport and train station practice.',
        ]);
    }

    public function test_update_is_rate_limited_by_user(): void
    {
        $user = $this->signIn();
        $deck = Deck::factory()->for($user)->create([
            'name' => 'Original User Deck',
            'description' => null,
        ]);
        $otherUser = User::factory()->create();
        $otherDeck = Deck::factory()->for($otherUser)->create([
            'name' => 'Original Other Deck',
            'description' => null,
        ]);

        $this->withDeckRateLimitOverride(
            DeckRateLimiter::UPDATE_NAME,
            [$user->id, $otherUser->id],
            function () use ($deck, $otherDeck, $otherUser, $user): void {
                foreach ([1, 2] as $attempt) {
                    $this
                        ->putJson("/api/decks/{$deck->id}", $this->deckUpdatePayload("User Deck {$attempt}"))
                        ->assertOk();
                }

                $this->signIn($otherUser);

                $this
                    ->putJson("/api/decks/{$otherDeck->id}", $this->deckUpdatePayload('Other User Deck'))
                    ->assertOk();

                $this->signIn($user);

                $this
                    ->putJson("/api/decks/{$deck->id}", $this->deckUpdatePayload('Blocked User Deck'))
                    ->assertTooManyRequests()
                    ->assertHeader('X-RateLimit-Limit', '2')
                    ->assertHeader('X-RateLimit-Remaining', '0')
                    ->assertHeader('Retry-After');

                $this
                    ->getJson("/api/decks/{$deck->id}")
                    ->assertOk()
                    ->assertJsonPath('data.name', 'User Deck 2');

                $this->assertSame('User Deck 2', $deck->refresh()->name);
                $this->assertSame('Other User Deck', $otherDeck->refresh()->name);
            },
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidDeckPayloadProvider')]
    public function test_it_rejects_invalid_deck_payloads(array $payload, array $errors): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->putJson("/api/decks/{$deck->id}", $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => $deck->name,
            'description' => $deck->description,
        ]);
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidDeckPayloadProvider(): array
    {
        return [
            'blank name' => [['name' => '   ', 'description' => null], ['name']],
            'non-string description' => [['name' => 'Italian Travel', 'description' => ['not a string']], ['description']],
            'non-string name' => [['name' => 123, 'description' => null], ['name']],
            'oversized description' => [['name' => 'Italian Travel', 'description' => str_repeat('a', 10_001)], ['description']],
            'oversized name' => [['name' => str_repeat('a', 256), 'description' => null], ['name']],
            'missing required fields' => [[], ['name', 'description']],
            'missing description' => [['name' => 'Italian Travel'], ['description']],
            'missing name' => [['description' => null], ['name']],
        ];
    }

    public function test_it_hides_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->putJson("/api/decks/{$otherDeck->id}", [
            'name' => 'Italian Travel',
            'description' => null,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('decks', [
            'id' => $otherDeck->id,
            'name' => $otherDeck->name,
            'description' => $otherDeck->description,
        ]);
    }

    public function test_it_authorizes_before_validating_another_users_deck(): void
    {
        $this->signIn();
        $otherDeck = Deck::factory()->create();

        $response = $this->putJson("/api/decks/{$otherDeck->id}", [
            'name' => '   ',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['name', 'description']);
    }

    public function test_it_returns_not_found_for_a_missing_deck(): void
    {
        $this->signIn();
        $missingDeckId = (string) Str::ulid();

        $response = $this->putJson("/api/decks/{$missingDeckId}", [
            'name' => 'Italian Travel',
            'description' => null,
        ]);

        $response->assertNotFound();
    }

    public function test_it_returns_not_found_for_a_malformed_deck_id(): void
    {
        $this->signIn();

        $response = $this->putJson('/api/decks/not-a-ulid', []);

        $response->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $deck = Deck::factory()->create();

        $response = $this->putJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Travel',
            'description' => null,
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => $deck->name,
            'description' => $deck->description,
        ]);
    }

    public function test_it_does_not_accept_patch_updates(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $response = $this->patchJson("/api/decks/{$deck->id}", [
            'name' => 'Italian Travel',
            'description' => null,
        ]);

        $response->assertStatus(405);

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'name' => $deck->name,
            'description' => $deck->description,
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function deckUpdatePayload(string $name): array
    {
        return [
            'name' => $name,
            'description' => null,
        ];
    }
}
