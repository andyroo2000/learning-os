<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Study\InspectsStudyBrowserQueries;
use Tests\TestCase;

class StudyBrowserQueryEfficiencyApiTest extends TestCase
{
    use InspectsStudyBrowserQueries;
    use RefreshDatabase;

    public function test_it_uses_bounded_page_and_facet_queries_for_initial_filter_options(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'recognition card',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 1251,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => 'initial browser load',
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'production card',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1252,
            'source_notetype_name' => 'Japanese - Grammar',
            'search_text' => 'initial browser load',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/study/browser?q=initial%20browser%20load');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('filterOptions.noteTypes', ['Japanese - Grammar', 'Japanese - Vocab'])
            ->assertJsonPath('filterOptions.cardTypes', ['production', 'recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['new', 'review']);

        $cardSelects = $this->cardSelectQueries($queries);
        $facetSelects = $this->facetSelectQueries($cardSelects);

        $this->assertCount(3, $cardSelects, 'Initial browser loads should use grouped page, page-card, and unioned facet queries.');
        $this->assertCount(1, $this->groupSelectQueries($cardSelects), 'Initial browser loads should page note groups in SQL.');
        $this->assertCount(1, $this->pagedCardSelectQueries($cardSelects), 'Initial browser loads should hydrate only current page cards.');
        $this->assertCount(1, $facetSelects, 'Initial browser loads should use one unioned facet query.');
    }

    public function test_it_derives_initial_filter_options_from_the_full_result_set_not_the_current_page(): void
    {
        $deck = $this->signedInDeck();

        Card::factory()->for($deck)->create([
            'front_text' => 'first paged card',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 1301,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => 'paged browser load',
            'created_at' => now()->subDay(),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'second paged card',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1302,
            'source_notetype_name' => 'Japanese - Grammar',
            'search_text' => 'paged browser load',
            'created_at' => now(),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/study/browser?q=paged%20browser%20load&limit=1');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.noteId', '1302')
            ->assertJsonPath('filterOptions.noteTypes', ['Japanese - Grammar', 'Japanese - Vocab'])
            ->assertJsonPath('filterOptions.cardTypes', ['production', 'recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['new', 'review']);

        $this->assertIsString($response->json('nextCursor'));

        $cardSelects = $this->cardSelectQueries($queries);
        $facetSelects = $this->facetSelectQueries($cardSelects);

        $this->assertCount(3, $cardSelects, 'Paged browser loads should use grouped page, page-card, and unioned facet queries.');
        $this->assertCount(1, $this->groupSelectQueries($cardSelects), 'Paged browser loads should page note groups in SQL.');
        $this->assertCount(1, $this->pagedCardSelectQueries($cardSelects), 'Paged browser loads should hydrate only current page cards.');
        $this->assertCount(1, $facetSelects, 'Paged browser loads should keep filter options based on the full filtered result set.');
    }
}
