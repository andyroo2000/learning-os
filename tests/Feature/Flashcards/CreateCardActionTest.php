<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

class CreateCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_card_for_a_deck(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertTrue(Str::isUlid($card->id));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
    }

    public function test_it_uses_a_provided_ulid(): void
    {
        $deck = Deck::factory()->create();
        $id = (string) Str::ulid();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame(strtolower($id), $card->id);

        $this->assertDatabaseHas('cards', [
            'id' => strtolower($id),
            'deck_id' => $deck->id,
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: "  {$deck->id}  ",
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
        $this->assertSame('ciao', $card->front_text);
        $this->assertSame('hello', $card->back_text);
    }

    public function test_it_normalizes_uppercase_deck_ids(): void
    {
        $deck = Deck::factory()->create();

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: strtoupper($deck->id),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );

        $card = $result->card;

        $this->assertTrue($result->wasCreated);
        $this->assertSame($deck->id, $card->deck_id);
    }

    public function test_it_returns_existing_card_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '  ciao  ',
                backText: '  hello  ',
                id: strtoupper($id),
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_matches_legacy_untrimmed_text_for_idempotent_retries(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        $existingCard = Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => '  ciao  ',
            'back_text' => '  hello  ',
        ]);

        $result = app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($existingCard->is($card));
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_returns_existing_card_when_concurrent_create_wins_the_race(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        $createCard = new CreateCardAction(
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
        );

        $result = $createCard->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );

        $card = $result->card;

        $this->assertTrue($inserted);
        $this->assertSame($id, $card->id);
        $this->assertFalse($result->wasCreated);
        $this->assertDatabaseCount('cards', 1);
    }

    public function test_it_rethrows_the_unique_exception_when_the_race_winner_disappears_before_refetch(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;
        $deleted = false;

        $createCard = new CreateCardAction(
            afterClientIdPrecheckMiss: function (CreateCardData $data) use (&$inserted, $deck): void {
                if ($inserted || $data->id === null) {
                    return;
                }

                $inserted = true;

                DB::table('cards')->insert([
                    'id' => $data->id,
                    'deck_id' => $deck->id,
                    'front_text' => 'ciao',
                    'back_text' => 'hello',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            },
            afterClientIdUniqueConflict: function (CreateCardData $data) use (&$deleted): void {
                $deleted = DB::table('cards')->where('id', $data->id)->delete() === 1;
            },
        );

        try {
            $createCard->handle(
                CreateCardData::fromInput(
                    userId: $deck->user_id,
                    deckId: $deck->id,
                    frontText: 'ciao',
                    backText: 'hello',
                    id: $id,
                ),
            );

            $this->fail('The original unique constraint exception was not rethrown.');
        } catch (QueryException) {
            $this->assertTrue($inserted);
            $this->assertTrue($deleted);
            $this->assertDatabaseCount('cards', 0);
        }
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $deck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($deck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'salve',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_rejects_same_user_cross_deck_ulid_conflicts(): void
    {
        $sourceDeck = Deck::factory()->create();
        $targetDeck = Deck::factory()->for($sourceDeck->user)->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($sourceDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $targetDeck->user_id,
                deckId: $targetDeck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_throws_for_cross_user_ulid_conflicts_before_http_hides_them(): void
    {
        $targetDeck = Deck::factory()->create();
        $otherDeck = Deck::factory()->create();
        $id = strtolower((string) Str::ulid());

        Card::factory()->for($otherDeck)->create([
            'id' => $id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(CardConflictException::class);
        $this->expectExceptionMessage('Card ID already exists with different metadata.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $targetDeck->user_id,
                deckId: $targetDeck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: $id,
            ),
        );
    }

    public function test_it_fails_when_existing_card_owner_cannot_be_resolved(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->id = strtolower((string) Str::ulid());
        $card->setRelation('deck', null);

        Log::spy();

        $ownerIdFor = new ReflectionMethod(CreateCardAction::class, 'ownerIdFor');
        $ownerIdFor->setAccessible(true);

        try {
            $ownerIdFor->invoke(new CreateCardAction, $card);

            $this->fail('Owner resolution did not fail for an orphaned card.');
        } catch (LogicException $exception) {
            $this->assertSame('Card deck owner could not be resolved.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Card conflict owner could not be resolved.', [
                'card_id' => $card->id,
                'deck_id' => $card->deck_id,
            ]);
    }

    public function test_it_requires_the_deck_relation_for_conflict_owner_resolution(): void
    {
        $card = Card::factory()->create();
        $card->unsetRelation('deck');

        $ownerIdFor = new ReflectionMethod(CreateCardAction::class, 'ownerIdFor');
        $ownerIdFor->setAccessible(true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck relation must be eager-loaded for conflict resolution.');

        $ownerIdFor->invoke(new CreateCardAction, $card);
    }

    public function test_it_rejects_non_positive_user_ids(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card user ID must be a positive integer.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: 0,
                deckId: strtolower((string) Str::ulid()),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_invalid_deck_ulid(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: 'not-a-ulid',
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_missing_deck(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck does not exist.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $user->id,
                deckId: strtolower((string) Str::ulid()),
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_another_users_deck(): void
    {
        $deck = Deck::factory()->create();
        $otherUser = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Deck does not exist.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $otherUser->id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_front_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: '   ',
                backText: 'hello',
            ),
        );
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: '   ',
            ),
        );
    }

    public function test_it_rejects_invalid_provided_ulid(): void
    {
        $deck = Deck::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        app(CreateCardAction::class)->handle(
            CreateCardData::fromInput(
                userId: $deck->user_id,
                deckId: $deck->id,
                frontText: 'ciao',
                backText: 'hello',
                id: 'not-a-ulid',
            ),
        );
    }
}
