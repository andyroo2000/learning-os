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

    public function test_card_resource_defaults_missing_study_status_to_new(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'study_status' => null,
            'new_queue_position' => null,
        ], sync: true);

        $this->assertSame(
            'new',
            CardResource::make($card)->resolve()['study_status'],
        );
        $this->assertNull(CardResource::make($card)->resolve()['new_queue_position']);
    }

    public function test_card_resource_serializes_import_source_metadata(): void
    {
        $card = new Card;
        $card->setRawAttributes([
            'id' => '01jzq4nny5xbnzw14q1g68b2yt',
            'deck_id' => '01jzq4kkf4sx5ebxnyqcg3dwdg',
            'import_job_id' => '01k1j8n4st9y2aqj9b43r1dz0e',
            'source_kind' => 'anki_import',
            'source_card_id' => 701,
            'source_note_id' => 501,
            'source_deck_id' => 1700000000000,
            'source_notetype_name' => 'Basic',
            'source_template_ord' => 0,
            'front_text' => '会社',
            'back_text' => 'company',
        ], sync: true);

        $resource = CardResource::make($card)->resolve();

        $this->assertSame('01k1j8n4st9y2aqj9b43r1dz0e', $resource['import_job_id']);
        $this->assertSame('anki_import', $resource['source_kind']);
        $this->assertSame(701, $resource['source_card_id']);
        $this->assertSame(501, $resource['source_note_id']);
        $this->assertSame(1700000000000, $resource['source_deck_id']);
        $this->assertSame('Basic', $resource['source_notetype_name']);
        $this->assertSame(0, $resource['source_template_ord']);
    }
}
