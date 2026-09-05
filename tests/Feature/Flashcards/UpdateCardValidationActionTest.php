<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateCardValidationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_blank_front_text(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card front text is required.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '   ',
                backText: 'goodbye',
            ),
        );
    }

    public function test_it_rejects_blank_back_text(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card back text is required.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: '   ',
            ),
        );
    }

    public function test_it_rejects_blank_card_type_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must not be blank when provided.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
                cardType: '   ',
            ),
        );
    }

    public function test_it_rejects_malformed_card_type_for_direct_callers(): void
    {
        $card = $this->cardFor($this->signIn());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card type must be one of: recognition, production, cloze.');

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
                cardType: 'reverse',
            ),
        );
    }
}
