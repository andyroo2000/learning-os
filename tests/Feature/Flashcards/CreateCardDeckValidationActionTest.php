<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\CreateCardAction;
use App\Domain\Flashcards\Data\CreateCardData;
use App\Domain\Flashcards\Models\Deck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class CreateCardDeckValidationActionTest extends TestCase
{
    use RefreshDatabase;

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
}
