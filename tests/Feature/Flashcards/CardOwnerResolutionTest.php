<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CardOwnerResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_belongs_to_a_deck(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $this->assertTrue($card->deck->is($deck));
        $this->assertTrue($deck->cards->contains($card));
    }

    public function test_owner_user_id_fails_when_parent_deck_cannot_be_resolved(): void
    {
        $this->assertOwnerCannotBeResolved($this->ownerlessCard());
    }

    public function test_owner_user_id_fails_when_loaded_deck_has_no_owner(): void
    {
        $card = $this->ownerlessCard();
        $card->setRelation('deck', new Deck);

        $this->assertOwnerCannotBeResolved($card);
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_null(): void
    {
        $this->assertOwnerCannotBeResolved($this->cardWithSelectedOwner(null));
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_zero(): void
    {
        $this->assertOwnerCannotBeResolved($this->cardWithSelectedOwner(0));
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_negative(): void
    {
        $this->assertOwnerCannotBeResolved($this->cardWithSelectedOwner(-1));
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_empty(): void
    {
        $this->assertOwnerCannotBeResolved($this->cardWithSelectedOwner(''));
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_a_malformed_numeric_string(): void
    {
        $this->assertOwnerCannotBeResolved($this->cardWithSelectedOwner('3abc'));
    }

    public function test_owner_user_id_resolves_soft_deleted_parent_decks(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertSame($deck->user_id, $card->ownerUserId());
    }

    public function test_owner_user_id_uses_a_selected_owner_attribute(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $queriedCard = Card::query()
            ->select('cards.*')
            ->selectRaw('decks.user_id as owner_user_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereKey($card->id)
            ->sole();

        $this->assertSame($deck->user_id, $queriedCard->ownerUserId());
    }

    private function ownerlessCard(): Card
    {
        return new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
    }

    private function cardWithSelectedOwner(mixed $ownerUserId): Card
    {
        $card = $this->ownerlessCard();
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => $ownerUserId,
        ]);

        return $card;
    }

    private function assertOwnerCannotBeResolved(Card $card): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }
}
