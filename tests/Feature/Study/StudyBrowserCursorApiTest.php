<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Study\InspectsStudyBrowserQueries;
use Tests\TestCase;

class StudyBrowserCursorApiTest extends TestCase
{
    use InspectsStudyBrowserQueries;
    use RefreshDatabase;

    public function test_it_paginates_browser_rows_with_returned_cursor(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'first',
            'source_note_id' => 2001,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'second',
            'source_note_id' => 2002,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $firstPage = $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '2001');

        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('rows.0.noteId', '2002')
            ->assertJsonPath('nextCursor', null);
    }

    public function test_it_handles_stale_browser_cursor_after_later_rows_are_deleted(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'remaining cursor row',
            'source_note_id' => 2051,
            'source_notetype_name' => 'Remaining Type',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $deletedCard = Card::factory()->for($deck)->create([
            'front_text' => 'deleted cursor row',
            'source_note_id' => 2052,
            'source_notetype_name' => 'Deleted Type',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $firstPage = $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1');

        $firstPage
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.0.noteId', '2051');

        $cursor = $firstPage->json('nextCursor');
        $this->assertIsString($cursor);

        $deletedCard->delete();

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('filterOptions.noteTypes', ['Remaining Type']);
    }

    public function test_it_handles_stale_browser_cursor_after_all_rows_are_deleted(): void
    {
        $deck = $this->signedInDeck();

        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'first deleted cursor row',
            'source_note_id' => 2061,
            'source_notetype_name' => 'Deleted Type',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'second deleted cursor row',
            'source_note_id' => 2062,
            'source_notetype_name' => 'Deleted Type',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $firstPage = $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1');

        $cursor = $firstPage
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->json('nextCursor');
        $this->assertIsString($cursor);

        $firstCard->delete();
        $secondCard->delete();

        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=asc&limit=1&cursor='.rawurlencode($cursor))
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('filterOptions.noteTypes', []);
    }
}
