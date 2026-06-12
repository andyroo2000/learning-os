<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudyBrowserCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_study_browser_rows_grouped_by_source_note(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCreatedAt = Carbon::parse('2026-06-01T09:15:00Z');
        $firstUpdatedAt = Carbon::parse('2026-06-04T09:15:00Z');
        $secondCreatedAt = Carbon::parse('2026-06-02T09:15:00Z');
        $secondUpdatedAt = Carbon::parse('2026-06-04T10:15:00Z');
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_kind' => 'anki_import',
            'source_note_id' => 1001,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 0,
            'prompt_json' => ['cueText' => '会社'],
            'search_text' => '会社 company',
            'created_at' => $firstCreatedAt,
            'updated_at' => $firstUpdatedAt,
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => '会社 production',
            'back_text' => 'company',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1001,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 1,
            'search_text' => '会社 production company',
            'created_at' => $secondCreatedAt,
            'updated_at' => $secondUpdatedAt,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => '水',
            'back_text' => 'water',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1002,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => '水 water',
        ]);
        Card::factory()->create([
            'front_text' => 'hidden',
            'search_text' => '会社 hidden',
            'source_note_id' => 1003,
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-04T11:00:00Z'),
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-04T12:00:00Z'),
        ]);
        CardReviewEvent::factory()->for($secondCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-04T13:00:00Z'),
        ]);

        $response = $this->getJson('/api/study/browser?q='.rawurlencode('会社').'&noteType=Japanese%20-%20Vocab&sortField=review_count&sortDirection=desc&limit=10');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('limit', 10)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('rows.0.noteId', '1001')
            ->assertJsonPath('rows.0.selectedCardId', (string) $firstCard->id)
            ->assertJsonPath('rows.0.displayText', '会社')
            ->assertJsonPath('rows.0.noteTypeName', 'Japanese - Vocab')
            ->assertJsonPath('rows.0.sourceKind', 'anki_import')
            ->assertJsonPath('rows.0.cardCount', 2)
            ->assertJsonPath('rows.0.reviewCount', 3)
            ->assertJsonPath('rows.0.lastReviewedAt', '2026-06-04T13:00:00.000000Z')
            ->assertJsonPath('rows.0.queueSummary.new', 1)
            ->assertJsonPath('rows.0.queueSummary.review', 1)
            ->assertJsonPath('rows.0.createdAt', $firstCreatedAt->toJSON())
            ->assertJsonPath('rows.0.updatedAt', $secondUpdatedAt->toJSON())
            ->assertJsonPath('filterOptions.noteTypes.0', 'Japanese - Vocab')
            ->assertJsonPath('filterOptions.cardTypes', ['production', 'recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['new', 'review']);

    }

    public function test_it_derives_filter_options_without_per_facet_card_queries(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->for($deck)->create([
            'front_text' => 'selected recognition card',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 1101,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => 'shared browser facet term',
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'alternate card type',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 1102,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => 'shared browser facet term',
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'alternate note type',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1103,
            'source_notetype_name' => 'Japanese - Grammar',
            'search_text' => 'shared browser facet term',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/study/browser?q=shared%20browser%20facet%20term&noteType=Japanese%20-%20Vocab&cardType=recognition');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.noteId', '1101')
            ->assertJsonPath('filterOptions.noteTypes', ['Japanese - Grammar', 'Japanese - Vocab'])
            ->assertJsonPath('filterOptions.cardTypes', ['production', 'recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['review']);

        $cardSelects = $this->cardSelectQueries($queries);
        $facetSelects = $this->facetSelectQueries($cardSelects);
        $groupSelects = $this->groupSelectQueries($cardSelects);
        $pagedCardSelects = $this->pagedCardSelectQueries($cardSelects);
        $standaloneReviewCountSelects = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_starts_with(strtolower($query['query']), 'select card_id, count(*) as review_count')
            && str_contains(strtolower($query['query']), 'from "card_review_events"'));
        $rowSelectsWithReviewCount = $cardSelects->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'review_events_count')
            && str_contains(strtolower($query['query']), 'review_events_max_reviewed_at')
            && str_contains(strtolower($query['query']), 'from "card_review_events"'));
        $filteredReviewCountSelects = $rowSelectsWithReviewCount->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'where "card_id" in'));

        $this->assertCount(1, $facetSelects, 'Study browser should use one unioned facet query instead of one query per facet.');
        $this->assertLessThanOrEqual(3, $cardSelects->count(), 'Study browser should keep card selects bounded to the group query, page-card query, and unioned facet query.');
        $this->assertCount(1, $groupSelects, 'Study browser should use one grouped page query with total_rows.');
        $this->assertCount(1, $pagedCardSelects, 'Study browser should hydrate only cards for the current page groups.');
        $this->assertCount(0, $standaloneReviewCountSelects, 'Study browser should not run a standalone review-count query on each page load.');
        $this->assertCount(2, $rowSelectsWithReviewCount, 'Study browser should load review counts in the group and page-card queries.');
        $this->assertCount(2, $filteredReviewCountSelects, 'Study browser should filter review-count aggregation to matching cards in both bounded queries.');
    }

    public function test_it_filters_browser_rows_and_filter_options_by_course_and_deck_ids(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeckInCourse = $this->deckFor($user, ['course_id' => $course->id]);
        $outsideCourseDeck = $this->deckFor($user);
        $matchingCard = Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 1151,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => '会社 scoped browser',
        ]);
        Card::factory()->for($otherDeckInCourse)->create([
            'front_text' => '会社 same course',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1152,
            'source_notetype_name' => 'Japanese - Grammar',
            'search_text' => '会社 scoped browser',
        ]);
        Card::factory()->for($outsideCourseDeck)->create([
            'front_text' => '会社 outside course',
            'card_type' => CardType::Cloze,
            'study_status' => CardStudyStatus::Buried,
            'source_note_id' => 1153,
            'source_notetype_name' => 'Outside Course',
            'search_text' => '会社 scoped browser',
        ]);

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?q='.rawurlencode(' 会社 scoped ')
                .'&course_id='.rawurlencode(' '.strtoupper($course->id).' ')
                .'&deck_id='.rawurlencode(' '.strtoupper($deck->id).' ')
                .'&cardType=recognition');

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.noteId', '1151')
            ->assertJsonPath('rows.0.selectedCardId', (string) $matchingCard->id)
            ->assertJsonPath('filterOptions.noteTypes', ['Japanese - Vocab'])
            ->assertJsonPath('filterOptions.cardTypes', ['recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['review']);
    }

    public function test_it_returns_empty_for_cross_user_browser_deck_filters(): void
    {
        $user = $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        Card::factory()->for($otherDeck)->create([
            'front_text' => 'hidden browser note',
            'source_note_id' => 1154,
        ]);
        Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'visible different deck note',
            'source_note_id' => 1155,
        ]);

        $this->getJson('/api/study/browser?deckId='.$otherDeck->id)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'rows')
            ->assertJsonPath('filterOptions.noteTypes', []);
    }

    public function test_it_returns_sorted_filter_options_without_filters(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        $betaCard = Card::factory()->for($deck)->create([
            'front_text' => 'beta recognition',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 1201,
            'source_notetype_name' => 'Beta',
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'alpha cloze',
            'card_type' => CardType::Cloze,
            'study_status' => CardStudyStatus::Buried,
            'source_note_id' => 1202,
            'source_notetype_name' => 'Alpha',
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/study/browser');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('rows.1.noteId', '1201')
            ->assertJsonPath('rows.1.selectedCardId', (string) $betaCard->id)
            ->assertJsonPath('rows.1.sourceKind', 'native')
            ->assertJsonPath('rows.1.lastReviewedAt', null)
            ->assertJsonPath('filterOptions.noteTypes', ['Alpha', 'Beta'])
            ->assertJsonPath('filterOptions.cardTypes', ['cloze', 'recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['buried', 'new']);

        $this->assertArrayHasKey('lastReviewedAt', $response->json('rows.1'));

        $cardSelects = $this->cardSelectQueries($queries);
        $facetSelects = $this->facetSelectQueries($cardSelects);

        $this->assertCount(3, $cardSelects, 'Initial browser loads should use grouped page, page-card, and unioned facet queries.');
        $this->assertCount(1, $this->groupSelectQueries($cardSelects), 'Initial browser loads should page note groups in SQL.');
        $this->assertCount(1, $this->pagedCardSelectQueries($cardSelects), 'Initial browser loads should hydrate only current page cards.');
        $this->assertCount(1, $facetSelects, 'Initial browser loads should use one unioned facet query.');
    }

    public function test_it_uses_bounded_page_and_facet_queries_for_initial_filter_options(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

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
        $user = $this->signIn();
        $deck = $this->deckFor($user);

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

    public function test_it_paginates_browser_rows_with_returned_cursor(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

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
        $user = $this->signIn();
        $deck = $this->deckFor($user);

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

    public function test_it_orders_equal_sort_values_with_a_stable_note_id_tiebreaker(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
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
        $user = $this->signIn();
        $deck = $this->deckFor($user);
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
    }

    public function test_it_sorts_browser_rows_by_display_text_case_insensitively(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->for($deck)->create([
            'front_text' => 'banana',
            'source_note_id' => 2202,
            'prompt_json' => ['cueText' => 'banana'],
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'apple',
            'source_note_id' => 2201,
            'prompt_json' => ['cueText' => 'Apple'],
        ]);

        $this->getJson('/api/study/browser?sortField=sort_field&sortDirection=asc')
            ->assertOk()
            ->assertJsonPath('rows.0.displayText', 'Apple')
            ->assertJsonPath('rows.1.displayText', 'banana');
    }

    public function test_it_uses_card_ids_for_rows_without_source_note_ids(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create([
            'front_text' => 'unsourced imported card',
            'source_note_id' => null,
            'search_text' => 'unsourced imported card',
        ]);

        $this->getJson('/api/study/browser?q=unsourced')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.noteId', (string) $card->id);
    }

    public function test_it_chooses_display_text_by_prompt_answer_priority(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create([
            'front_text' => 'front fallback',
            'source_note_id' => 2301,
            'prompt_json' => [
                'cueText' => ' prompt cue ',
                'expression' => 'prompt expression',
                'clozeText' => 'prompt cloze',
                'text' => 'prompt text',
            ],
            'answer_json' => [
                'cueText' => 'answer cue',
            ],
            'search_text' => 'priority display note',
        ]);

        $this->getJson('/api/study/browser?q=priority')
            ->assertOk()
            ->assertJsonPath('rows.0.displayText', 'prompt cue');
    }

    public function test_it_omits_malformed_raw_enum_facet_values_without_failing(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create([
            'front_text' => '',
            'source_note_id' => 2401,
            'source_notetype_name' => 'Legacy Note Type',
            'search_text' => 'legacy malformed enum card',
        ]);

        DB::table('cards')
            ->where('id', $card->id)
            ->update([
                'card_type' => 'legacy-card-type',
                'prompt_json' => null,
                'answer_json' => null,
                'study_status' => 'legacy-study-status',
            ]);

        $this->getJson('/api/study/browser?q=legacy')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.noteId', '2401')
            ->assertJsonPath('rows.0.displayText', (string) $card->id)
            ->assertJsonPath('rows.0.queueSummary.new', 1)
            ->assertJsonPath('filterOptions.noteTypes', ['Legacy Note Type'])
            ->assertJsonPath('filterOptions.cardTypes', [])
            ->assertJsonPath('filterOptions.queueStates', []);
    }

    public function test_it_excludes_deleted_cards_deleted_decks_and_other_users_from_rows_and_filter_options(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $activeCard = Card::factory()->for($deck)->create([
            'front_text' => 'active card',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 2501,
            'source_notetype_name' => 'Visible Note Type',
            'search_text' => 'shared browser search active',
        ]);
        $deletedCard = Card::factory()->for($deck)->create([
            'front_text' => 'deleted card',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 2502,
            'source_notetype_name' => 'Deleted Note Type',
            'search_text' => 'shared browser search deleted',
        ]);
        $deletedDeck = $this->deckFor($user);
        Card::factory()->for($deletedDeck)->create([
            'front_text' => 'deleted deck card',
            'card_type' => CardType::Cloze,
            'study_status' => CardStudyStatus::Buried,
            'source_note_id' => 2503,
            'source_notetype_name' => 'Deleted Deck Note Type',
            'search_text' => 'shared browser search deleted deck',
        ]);
        Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'front_text' => 'other user card',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::Suspended,
            'source_note_id' => 2504,
            'source_notetype_name' => 'Other User Note Type',
            'search_text' => 'shared browser search other user',
        ]);

        $deletedCard->delete();
        $deletedDeck->delete();

        $this->getJson('/api/study/browser?q=shared%20browser%20search')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.noteId', (string) $activeCard->source_note_id)
            ->assertJsonPath('filterOptions.noteTypes', ['Visible Note Type'])
            ->assertJsonPath('filterOptions.cardTypes', ['recognition'])
            ->assertJsonPath('filterOptions.queueStates', ['review']);
    }

    public function test_it_normalizes_browser_query_inputs_without_trim_strings_middleware(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create([
            'front_text' => '会社',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_note_id' => 3001,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => '会社 company',
        ]);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?q=%20%E4%BC%9A%E7%A4%BE%20&cardType=%20RECOGNITION%20&queueState=%20REVIEW%20&sortField=%20CREATED_ON%20&sortDirection=%20DESC%20&limit=%20%2B1%20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('limit', 1)
            ->assertJsonPath('rows.0.noteId', '3001');
    }

    public function test_it_rejects_blank_text_filters_without_trim_strings_middleware(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?q=%20%20%20')
            ->assertJsonValidationErrors(['q']);

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?noteType=%20%20%20')
            ->assertJsonValidationErrors(['noteType']);
    }

    public function test_it_validates_browser_query_inputs(): void
    {
        $this->signIn();

        $this->getJson('/api/study/browser?sortField=bad&sortDirection=sideways')
            ->assertJsonValidationErrors(['sortField', 'sortDirection']);
        $this->getJson('/api/study/browser?q='.str_repeat('a', 201))
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/study/browser?noteType='.str_repeat('a', 201))
            ->assertJsonValidationErrors(['noteType']);
        $this->getJson('/api/study/browser?limit=0')
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit=101')
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?limit=%20-1%20')
            ->assertJsonValidationErrors(['limit']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?limit=%20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit=abc')
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?limit[]=25')
            ->assertJsonValidationErrors(['limit']);
        $this->getJson('/api/study/browser?q[]=company')
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/study/browser?noteType[]=Japanese')
            ->assertJsonValidationErrors(['noteType']);
        $this->getJson('/api/study/browser?cardType[]=recognition')
            ->assertJsonValidationErrors(['cardType']);
        $this->getJson('/api/study/browser?queueState[]=review')
            ->assertJsonValidationErrors(['queueState']);
        $this->getJson('/api/study/browser?sortField[]=created_on')
            ->assertJsonValidationErrors(['sortField']);
        $this->getJson('/api/study/browser?sortDirection[]=desc')
            ->assertJsonValidationErrors(['sortDirection']);
        $this->getJson('/api/study/browser?courseId=not-a-ulid')
            ->assertJsonValidationErrors(['courseId']);
        $this->getJson('/api/study/browser?courseId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['courseId']);
        $this->getJson('/api/study/browser?course_id=not-a-ulid')
            ->assertJsonValidationErrors(['course_id']);
        $this->getJson('/api/study/browser?course_id[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['course_id']);
        $this->getJson('/api/study/browser?deckId=not-a-ulid')
            ->assertJsonValidationErrors(['deckId']);
        $this->getJson('/api/study/browser?deckId[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['deckId']);
        $this->getJson('/api/study/browser?deck_id=not-a-ulid')
            ->assertJsonValidationErrors(['deck_id']);
        $this->getJson('/api/study/browser?deck_id[]=01ktt2q9z5vfpxsqgc3mwrdh35')
            ->assertJsonValidationErrors(['deck_id']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?courseId=%20')
            ->assertJsonValidationErrors(['courseId']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?course_id=%20')
            ->assertJsonValidationErrors(['course_id']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?deckId=%20')
            ->assertJsonValidationErrors(['deckId']);
        $this
            ->withoutMiddleware(TrimStrings::class)
            ->getJson('/api/study/browser?deck_id=%20')
            ->assertJsonValidationErrors(['deck_id']);
        $this->getJson('/api/study/browser?cursor=')
            ->assertJsonValidationErrors(['cursor']);
        $this->getJson('/api/study/browser?cursor[]=abc')
            ->assertJsonValidationErrors(['cursor']);
        $this->getJson('/api/study/browser?cursor=not-a-cursor')
            ->assertJsonValidationErrors(['cursor']);
    }

    public function test_it_rejects_conflicting_browser_scope_filter_aliases(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user);
        $otherDeck = $this->deckFor($user);

        $this->getJson("/api/study/browser?courseId={$course->id}&course_id={$otherCourse->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['courseId']);

        $this->getJson("/api/study/browser?deckId={$deck->id}&deck_id={$otherDeck->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['deckId']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/browser')
            ->assertUnauthorized();
    }

    /**
     * @param  Collection<int, array{query: string}>  $queries
     * @return Collection<int, array{query: string}>
     */
    private function cardSelectQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_starts_with($sql, 'select')
                && (str_contains($sql, 'from "cards"') || str_contains($sql, 'from `cards`'));
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function facetSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, ' as facet')
                && str_contains($sql, ' union ');
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function groupSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'total_rows')
                && str_contains($sql, 'group by');
        });
    }

    /**
     * @param  Collection<int, array{query: string}>  $cardQueries
     * @return Collection<int, array{query: string}>
     */
    private function pagedCardSelectQueries(Collection $cardQueries): Collection
    {
        return $cardQueries->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'source_note_id')
                && str_contains($sql, ' in ')
                && ! str_contains($sql, 'total_rows')
                && ! str_contains($sql, ' as facet');
        });
    }
}
