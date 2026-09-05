<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Study\InspectsStudyBrowserQueries;
use Tests\TestCase;

class StudyBrowserPaginationApiTest extends TestCase
{
    use InspectsStudyBrowserQueries;
    use RefreshDatabase;

    public function test_it_paginates_browser_rows_by_card_count_aggregate(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'single-card note',
            'source_note_id' => 2071,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'first multi-card note',
            'source_note_id' => 2072,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'second multi-card note',
            'source_note_id' => 2072,
        ]);

        $firstPage = $this->getJson('/api/study/browser?sortField=card_count&sortDirection=desc&limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.noteId', '2072')
            ->assertJsonPath('rows.0.cardCount', 2);

        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/browser?sortField=card_count&sortDirection=desc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '2071')
            ->assertJsonPath('rows.0.cardCount', 1)
            ->assertJsonPath('nextCursor', null);
    }

    public function test_it_paginates_browser_rows_by_updated_on_aggregate(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'older updated note',
            'source_note_id' => 2076,
            'updated_at' => now()->subDays(2),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'newer updated note',
            'source_note_id' => 2077,
            'updated_at' => now()->subDay(),
        ]);

        $firstPage = $this->getJson('/api/study/browser?sortField=updated_on&sortDirection=desc&limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.noteId', '2077');

        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/browser?sortField=updated_on&sortDirection=desc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '2076')
            ->assertJsonPath('nextCursor', null);
    }

    public function test_it_paginates_browser_rows_by_review_count_aggregate(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'unreviewed note',
            'source_note_id' => 2081,
        ]);
        $firstReviewedCard = Card::factory()->for($deck)->create([
            'front_text' => 'first reviewed note card',
            'source_note_id' => 2082,
        ]);
        $secondReviewedCard = Card::factory()->for($deck)->create([
            'front_text' => 'second reviewed note card',
            'source_note_id' => 2082,
        ]);
        CardReviewEvent::factory()->for($firstReviewedCard)->count(2)->create();
        CardReviewEvent::factory()->for($secondReviewedCard)->create();

        $firstPage = $this->getJson('/api/study/browser?sortField=review_count&sortDirection=desc&limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.noteId', '2082')
            ->assertJsonPath('rows.0.reviewCount', 3);

        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/browser?sortField=review_count&sortDirection=desc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '2081')
            ->assertJsonPath('rows.0.reviewCount', 0)
            ->assertJsonPath('nextCursor', null);
    }

    public function test_it_orders_equal_sort_values_with_a_stable_note_id_tiebreaker(): void
    {
        $deck = $this->signedInDeck();
        $timestamp = now()->subHour();

        Card::factory()->for($deck)->create([
            'front_text' => 'second tiebreak note',
            'source_note_id' => 10,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'first tiebreak note',
            'source_note_id' => 9,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc')
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '9')
            ->assertJsonPath('rows.1.noteId', '10');

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=desc')
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '10')
            ->assertJsonPath('rows.1.noteId', '9');
    }

    public function test_it_orders_sourced_groups_before_unsourced_groups_when_sort_values_tie(): void
    {
        $deck = $this->signedInDeck();
        $timestamp = now()->subHour();

        $unsourcedCard = Card::factory()->for($deck)->create([
            'front_text' => 'unsourced tie row',
            'source_note_id' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'sourced tie row',
            'source_note_id' => 11,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc')
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '11')
            ->assertJsonPath('rows.1.noteId', (string) $unsourcedCard->id);

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=desc')
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '11')
            ->assertJsonPath('rows.1.noteId', (string) $unsourcedCard->id);
    }
}
