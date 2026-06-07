<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\ShowStudyBrowserNoteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowStudyBrowserNoteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_normalizes_unsourced_card_note_ids_for_direct_callers(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'unsourced detail card',
            'source_note_id' => null,
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle(
            $user->id,
            ' '.strtolower($card->id).' ',
        );

        $this->assertNotNull($result);
        $this->assertSame($card->id, $result->noteId);
        $this->assertSame($card->id, $result->selectedCardId);
    }

    public function test_it_resolves_exact_uppercase_unsourced_card_note_ids_for_direct_callers(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'uppercase unsourced detail card',
            'source_note_id' => null,
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, strtoupper($card->id));

        $this->assertNotNull($result);
        $this->assertSame($card->id, $result->noteId);
        $this->assertSame($card->id, $result->selectedCardId);
    }

    public function test_it_prefers_the_exact_unsourced_card_id_candidate_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $cardId = strtolower((string) str()->ulid());
        $lowercaseCard = Card::factory()->for($deck)->create([
            'id' => $cardId,
            'front_text' => 'lowercase unsourced card',
            'source_note_id' => null,
        ]);
        Card::factory()->for($deck)->create([
            'id' => strtoupper($cardId),
            'front_text' => 'uppercase unsourced card',
            'source_note_id' => null,
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, $cardId);

        $this->assertNotNull($result);
        $this->assertSame($lowercaseCard->id, $result->noteId);
        $this->assertSame($lowercaseCard->id, $result->selectedCardId);
        $this->assertCount(1, $result->cards);
    }

    public function test_it_trims_sourced_note_ids_for_direct_callers(): void
    {
        $user = $this->signIn();
        Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'sourced detail card',
            'source_note_id' => 7001,
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, ' 7001 ');

        $this->assertNotNull($result);
        $this->assertSame('7001', $result->noteId);
    }

    public function test_it_returns_null_for_malformed_unsourced_note_ids(): void
    {
        $user = $this->signIn();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, 'not-a-ulid');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertNull($result);
        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'from "cards"')),
            'Malformed unsourced note IDs should return null before querying cards.',
        );
    }

    public function test_it_returns_null_for_valid_unsourced_note_ids_without_matching_cards(): void
    {
        $result = app(ShowStudyBrowserNoteAction::class)->handle(
            $this->signIn()->id,
            strtolower((string) str()->ulid()),
        );

        $this->assertNull($result);
    }

    public function test_it_returns_null_for_blank_direct_note_ids(): void
    {
        $result = app(ShowStudyBrowserNoteAction::class)->handle($this->signIn()->id, '   ');

        $this->assertNull($result);
    }
}
