<?php

namespace Tests\Unit\Resources\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Http\Resources\Flashcards\CardResource;
use App\Http\Resources\Flashcards\DeckResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlashcardResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deck_resource_serializes_deleted_at_for_tombstones(): void
    {
        $deck = $this->deckFor(User::factory()->create());

        $deck->delete();
        $deck = Deck::withTrashed()->findOrFail($deck->id);

        $this->assertNotNull($deck->deleted_at);
        $this->assertSame(
            $deck->deleted_at->toJSON(),
            DeckResource::make($deck)->resolve()['deleted_at'],
        );
    }

    public function test_card_resource_serializes_deleted_at_for_tombstones(): void
    {
        $card = $this->cardFor(User::factory()->create());

        $card->delete();
        $card = Card::withTrashed()->findOrFail($card->id);

        $this->assertNotNull($card->deleted_at);
        $this->assertSame(
            $card->deleted_at->toJSON(),
            CardResource::make($card)->resolve()['deleted_at'],
        );
    }

    public function test_card_resource_serializes_course_id_from_its_deck(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $card = Card::factory()->for($deck)->create();

        $this->assertSame(
            $course->id,
            CardResource::make($card)->resolve()['course_id'],
        );
    }
}
