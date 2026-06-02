<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Actions\DeleteDeckAction;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeleteDeckActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_soft_deletes_a_deck_and_its_cards(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->for($deck)->create();

        $result = app(DeleteDeckAction::class)->handle($deck);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($deck, $result->deck);
        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_it_soft_deletes_an_empty_deck(): void
    {
        $deck = Deck::factory()->create();

        $result = app(DeleteDeckAction::class)->handle($deck);

        $this->assertTrue($result->wasDeleted);
        $this->assertSame($deck, $result->deck);
        $this->assertSoftDeleted('decks', [
            'id' => $deck->id,
        ]);
    }

    public function test_it_no_ops_when_the_deck_is_already_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $deck->delete();
            $originalDeckDeletedAt = $deck->refresh()->deleted_at;
            $originalCardDeletedAt = $card->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteDeckAction::class)->handle($deck);

            $this->assertFalse($result->wasDeleted);
            $this->assertSame($deck, $result->deck);
            $this->assertDatabaseHas('decks', [
                'id' => $deck->id,
                'deleted_at' => $originalDeckDeletedAt?->toDateTimeString(),
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => $originalCardDeletedAt?->toDateTimeString(),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_it_preserves_independently_deleted_card_timestamps(): void
    {
        $deck = Deck::factory()->create();
        $independentlyDeletedCard = Card::factory()->for($deck)->create();
        $activeCard = Card::factory()->for($deck)->create();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00'));

        try {
            $independentlyDeletedCard->delete();
            $originalDeletedAt = $independentlyDeletedCard->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:01'));

            $result = app(DeleteDeckAction::class)->handle($deck);

            $this->assertTrue($result->wasDeleted);
            $this->assertSame($deck, $result->deck);
            $this->assertSoftDeleted('cards', [
                'id' => $activeCard->id,
            ]);
            $this->assertDatabaseHas('cards', [
                'id' => $independentlyDeletedCard->id,
                'deleted_at' => $originalDeletedAt?->toDateTimeString(),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
