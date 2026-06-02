<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateCardActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_card_text(): void
    {
        $card = $this->cardFor($this->signIn());

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        );
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame($card->id, $updatedCard->id);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  arrivederci  ',
                backText: '  goodbye  ',
            ),
        );
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('arrivederci', $updatedCard->front_text);
        $this->assertSame('goodbye', $updatedCard->back_text);
    }

    public function test_it_marks_unchanged_when_normalized_text_matches_the_existing_card(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  ciao  ',
                backText: '  hello  ',
            ),
        );

        $this->assertFalse($result->wasUpdated);
        $this->assertSame($card->id, $result->card->id);
    }

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
}
