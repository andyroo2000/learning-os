<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

class CreateCardConflictActionTest extends TestCase
{
    use RefreshDatabase;

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
            $ownerIdFor->invoke(app(CreateCardAction::class), $card);

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

        $ownerIdFor->invoke(app(CreateCardAction::class), $card);
    }
}
