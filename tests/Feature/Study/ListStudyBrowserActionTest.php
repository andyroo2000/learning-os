<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ListStudyBrowserAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use UnexpectedValueException;

class ListStudyBrowserActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_groups_cards_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => '学校',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_kind' => 'anki_import',
            'source_note_id' => 4001,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 0,
            'search_text' => '学校 school',
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => '学校 production',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_note_id' => 4001,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 1,
            'search_text' => '学校 production school',
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-01T10:00:00Z'),
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            q: '学校',
            noteType: 'Japanese - Vocab',
            cardType: 'recognition',
            sortField: 'review_count',
            sortDirection: 'desc',
            limit: 10,
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame('4001', $result['rows'][0]['noteId']);
        $this->assertSame((string) $firstCard->id, $result['rows'][0]['selectedCardId']);
        $this->assertSame('anki_import', $result['rows'][0]['sourceKind']);
        $this->assertSame(1, $result['rows'][0]['cardCount']);
        $this->assertSame(1, $result['rows'][0]['reviewCount']);
        $this->assertSame('2026-06-01T10:00:00.000000Z', $result['rows'][0]['lastReviewedAt']);
        $this->assertSame(['production', 'recognition'], $result['filterOptions']['cardTypes']);
    }

    public function test_it_groups_copied_convolab_cards_by_their_note_identifier(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $noteId = '33afc682-eef1-46f2-849a-13a9f80ec11e';
        $firstCardId = '1b6df437-414e-45c3-b12a-c2da90782ee5';
        $noteCreatedAt = Carbon::parse('2026-06-01T09:15:00.844Z');
        $noteUpdatedAt = Carbon::parse('2026-06-04T10:30:00.108Z');

        $firstCard = Card::factory()->for($deck)->create([
            'convolab_id' => $firstCardId,
            'convolab_note_id' => $noteId,
            'convolab_note_created_at' => $noteCreatedAt,
            'convolab_note_updated_at' => $noteUpdatedAt,
            'front_text' => 'copied prompt',
            'source_note_id' => 4201,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 0,
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'convolab_id' => '75d30d7b-a85d-44c0-9fd0-b6a85193d7bd',
            'convolab_note_id' => $noteId,
            'convolab_note_created_at' => $noteCreatedAt,
            'convolab_note_updated_at' => $noteUpdatedAt,
            'front_text' => 'copied answer',
            'source_note_id' => 4201,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 1,
        ]);
        DB::table('cards')
            ->whereIn('id', [$firstCard->id, $secondCard->id])
            ->update([
                'convolab_note_created_at' => '2026-06-01 09:15:00.844',
                'convolab_note_updated_at' => '2026-06-04 10:30:00.108',
            ]);

        $result = app(ListStudyBrowserAction::class)->handle(userId: $user->id);

        $this->assertSame(1, $result['total']);
        $this->assertSame($noteId, $result['rows'][0]['noteId']);
        $this->assertSame($firstCardId, $result['rows'][0]['selectedCardId']);
        $this->assertSame(2, $result['rows'][0]['cardCount']);
        $this->assertSame('2026-06-01T09:15:00.844000Z', $result['rows'][0]['createdAt']);
        $this->assertSame('2026-06-04T10:30:00.108000Z', $result['rows'][0]['updatedAt']);
    }

    public function test_it_paginates_mixed_copied_sourced_and_unsourced_groups_with_stable_ties(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $tiedAt = Carbon::parse('2026-06-01T09:15:00Z');
        $copiedNoteId = '33afc682-eef1-46f2-849a-13a9f80ec11e';

        Card::factory()->for($deck)->create([
            'convolab_id' => '1b6df437-414e-45c3-b12a-c2da90782ee5',
            'convolab_note_id' => $copiedNoteId,
            'convolab_note_created_at' => $tiedAt,
            'convolab_note_updated_at' => $tiedAt,
            'front_text' => 'copied group',
            'source_note_id' => 4301,
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'numeric source group',
            'source_note_id' => 4302,
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
        ]);
        $firstUnsourced = Card::factory()->for($deck)->create([
            'front_text' => 'first unsourced group',
            'source_note_id' => null,
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
        ]);
        $secondUnsourced = Card::factory()->for($deck)->create([
            'front_text' => 'second unsourced group',
            'source_note_id' => null,
            'created_at' => $tiedAt,
            'updated_at' => $tiedAt,
        ]);
        $orderedUnsourcedIds = collect([$firstUnsourced->id, $secondUnsourced->id])
            ->map(fn (mixed $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();

        $firstPage = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            sortField: 'created_on',
            sortDirection: 'asc',
            limit: 3,
        );

        $this->assertSame(4, $firstPage['total']);
        $this->assertSame(
            [$copiedNoteId, '4302', $orderedUnsourcedIds[0]],
            collect($firstPage['rows'])->pluck('noteId')->all(),
        );
        $this->assertNotNull($firstPage['nextCursor']);

        $secondPage = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            sortField: 'created_on',
            sortDirection: 'asc',
            cursor: $firstPage['nextCursor'],
            limit: 3,
        );

        $this->assertSame(4, $secondPage['total']);
        $this->assertSame([$orderedUnsourcedIds[1]], collect($secondPage['rows'])->pluck('noteId')->all());
        $this->assertNull($secondPage['nextCursor']);
    }

    public function test_it_reports_group_metadata_with_legacy_blank_source_kind_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'group metadata prompt',
            'source_kind' => '',
            'source_note_id' => 4011,
            'source_template_ord' => 0,
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'group metadata answer',
            'source_kind' => 'anki_import',
            'source_note_id' => 4011,
            'source_template_ord' => 1,
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-01T10:00:00Z'),
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(userId: $user->id);

        $this->assertSame('4011', $result['rows'][0]['noteId']);
        $this->assertSame((string) $firstCard->id, $result['rows'][0]['selectedCardId']);
        // Legacy blank first-card provenance keeps the deterministic row fallback, even if siblings carry imported metadata.
        $this->assertSame('native', $result['rows'][0]['sourceKind']);
        $this->assertSame(1, $result['rows'][0]['reviewCount']);
        $this->assertSame('2026-06-01T10:00:00.000000Z', $result['rows'][0]['lastReviewedAt']);
    }

    public function test_it_reports_latest_review_across_group_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'latest review prompt',
            'source_kind' => 'anki_import',
            'source_note_id' => 4021,
            'source_template_ord' => 0,
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'latest review answer',
            'source_kind' => 'anki_import',
            'source_note_id' => 4021,
            'source_template_ord' => 1,
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-01T10:00:00Z'),
        ]);
        CardReviewEvent::factory()->for($secondCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-04T10:00:00Z'),
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(userId: $user->id);

        $this->assertSame('4021', $result['rows'][0]['noteId']);
        $this->assertSame((string) $firstCard->id, $result['rows'][0]['selectedCardId']);
        $this->assertSame('anki_import', $result['rows'][0]['sourceKind']);
        $this->assertSame(2, $result['rows'][0]['cardCount']);
        $this->assertSame(2, $result['rows'][0]['reviewCount']);
        $this->assertSame('2026-06-04T10:00:00.000000Z', $result['rows'][0]['lastReviewedAt']);
    }

    public function test_it_reports_null_last_reviewed_at_for_unreviewed_groups_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'unreviewed prompt',
            'source_note_id' => 4031,
            'source_template_ord' => 0,
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'unreviewed answer',
            'source_note_id' => 4031,
            'source_template_ord' => 1,
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(userId: $user->id);

        $this->assertSame('4031', $result['rows'][0]['noteId']);
        $this->assertSame((string) $firstCard->id, $result['rows'][0]['selectedCardId']);
        $this->assertSame(2, $result['rows'][0]['cardCount']);
        $this->assertSame(0, $result['rows'][0]['reviewCount']);
        $this->assertNull($result['rows'][0]['lastReviewedAt']);
    }

    public function test_it_normalizes_direct_note_type_and_sort_inputs(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create([
            'front_text' => 'older note',
            'source_note_id' => 4051,
            'source_notetype_name' => 'Japanese - Vocab',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'newer note',
            'source_note_id' => 4052,
            'source_notetype_name' => 'Japanese - Vocab',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            noteType: ' Japanese - Vocab ',
            sortField: ' CREATED_ON ',
            sortDirection: ' ASC ',
        );

        $this->assertSame(['4051', '4052'], collect($result['rows'])->pluck('noteId')->all());
    }

    public function test_it_filters_browser_rows_by_course_id_for_direct_callers(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $courseDeck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user);
        Card::factory()->for($courseDeck)->create([
            'front_text' => 'course scoped note',
            'source_note_id' => 4056,
            'source_notetype_name' => 'Japanese - Vocab',
            'search_text' => 'course scoped browser note',
        ]);
        Card::factory()->for($otherDeck)->create([
            'front_text' => 'outside course note',
            'source_note_id' => 4057,
            'source_notetype_name' => 'Japanese - Grammar',
            'search_text' => 'course scoped browser note',
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            q: 'course scoped browser note',
            courseId: ' '.strtoupper($course->id).' ',
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(['4056'], collect($result['rows'])->pluck('noteId')->all());
        $this->assertSame(['Japanese - Vocab'], $result['filterOptions']['noteTypes']);
    }

    public function test_it_filters_browser_rows_by_deck_id_for_direct_callers(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $deck = $this->deckFor($user, ['course_id' => $course->id]);
        $otherDeck = $this->deckFor($user, ['course_id' => $course->id]);
        Card::factory()->for($deck)->create([
            'front_text' => 'deck scoped note',
            'source_note_id' => 4058,
        ]);
        Card::factory()->for($otherDeck)->create([
            'front_text' => 'same course other deck note',
            'source_note_id' => 4059,
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            deckId: ' '.strtoupper($deck->id).' ',
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame(['4058'], collect($result['rows'])->pluck('noteId')->all());
    }

    public function test_it_returns_empty_when_browser_course_and_deck_filters_do_not_match(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->for($user)->create();
        $otherCourse = Course::factory()->for($user)->create();
        $otherCourseDeck = $this->deckFor($user, ['course_id' => $otherCourse->id]);
        Card::factory()->for($otherCourseDeck)->create([
            'front_text' => 'other course note',
            'source_note_id' => 4060,
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            courseId: $course->id,
            deckId: $otherCourseDeck->id,
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['filterOptions']['noteTypes']);
    }

    public function test_it_hides_browser_deck_filters_owned_by_other_users(): void
    {
        $user = $this->signIn();
        $otherDeck = $this->deckFor(User::factory()->create());
        Card::factory()->for($otherDeck)->create([
            'front_text' => 'other user note',
            'source_note_id' => 4061,
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            deckId: $otherDeck->id,
        );

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['rows']);
    }

    public function test_it_uses_group_timestamp_boundaries_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->for($deck)->create([
            'front_text' => 'older created card',
            'source_note_id' => 4053,
            'created_at' => Carbon::parse('2026-06-01T09:15:00Z'),
            'updated_at' => Carbon::parse('2026-06-02T09:15:00Z'),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'newer updated card',
            'source_note_id' => 4053,
            'created_at' => Carbon::parse('2026-06-03T09:15:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            sortField: 'created_on',
            sortDirection: 'asc',
        );

        $this->assertSame('4053', $result['rows'][0]['noteId']);
        $this->assertSame('2026-06-01T09:15:00.000000Z', $result['rows'][0]['createdAt']);
        $this->assertSame('2026-06-04T09:15:00.000000Z', $result['rows'][0]['updatedAt']);
    }

    public function test_it_rejects_rows_without_created_timestamps_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $card = Card::factory()->for($deck)->create([
            'front_text' => 'missing timestamp card',
            'source_note_id' => 4054,
        ]);
        DB::table('cards')
            ->where('id', $card->id)
            ->update(['created_at' => null]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study browser created_at timestamp is missing or invalid.');

        app(ListStudyBrowserAction::class)->handle(userId: $user->id);
    }

    public function test_it_rejects_rows_with_any_missing_updated_timestamp_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        Card::factory()->for($deck)->create([
            'front_text' => 'valid timestamp sibling',
            'source_note_id' => 4055,
            'updated_at' => Carbon::parse('2026-06-03T09:15:00Z'),
        ]);
        $missingTimestampCard = Card::factory()->for($deck)->create([
            'front_text' => 'missing updated timestamp sibling',
            'source_note_id' => 4055,
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
        ]);
        DB::table('cards')
            ->where('id', $missingTimestampCard->id)
            ->update(['updated_at' => null]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study browser updated_at timestamp is missing or invalid.');

        app(ListStudyBrowserAction::class)->handle(userId: $user->id);
    }

    public function test_it_treats_search_wildcards_as_literals_for_direct_callers(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $match = Card::factory()->for($deck)->create([
            'front_text' => 'literal wildcard note',
            'source_note_id' => 4101,
            'search_text' => 'Recall 100% of deck_1',
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'wildcard-shaped non-match',
            'source_note_id' => 4102,
            'search_text' => 'Recall 100 percent of deckA1',
        ]);

        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            q: '100% of deck_1',
        );

        $this->assertSame(1, $result['total']);
        $this->assertSame((string) $match->source_note_id, $result['rows'][0]['noteId']);
    }

    public function test_it_rejects_blank_note_type_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser noteType filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            noteType: '   ',
        );
    }

    public function test_it_rejects_blank_search_queries_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Card search query filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            q: '   ',
        );
    }

    public function test_it_rejects_blank_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser courseId filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            courseId: '   ',
        );
    }

    public function test_it_rejects_malformed_course_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser courseId filter must be a valid ULID.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            courseId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_blank_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser deckId filter must not be blank when provided.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            deckId: '   ',
        );
    }

    public function test_it_rejects_malformed_deck_filters_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser deckId filter must be a valid ULID.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            deckId: 'not-a-ulid',
        );
    }

    public function test_it_rejects_invalid_sort_controls_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser sortField must be one of:');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            sortField: 'last_seen',
        );
    }

    public function test_it_rejects_invalid_sort_directions_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser sortDirection must be one of:');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            sortDirection: 'sideways',
        );
    }

    public function test_it_uses_the_default_limit_for_direct_callers_when_limit_is_absent(): void
    {
        $result = app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
        );

        $this->assertSame(ListStudyBrowserAction::DEFAULT_LIMIT, $result['limit']);
    }

    public function test_it_accepts_boundary_limits_for_direct_callers(): void
    {
        $user = $this->signIn();

        $minimum = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            limit: 1,
        );
        $maximum = app(ListStudyBrowserAction::class)->handle(
            userId: $user->id,
            limit: ListStudyBrowserAction::MAX_LIMIT,
        );

        $this->assertSame(1, $minimum['limit']);
        $this->assertSame(ListStudyBrowserAction::MAX_LIMIT, $maximum['limit']);
    }

    public function test_it_rejects_invalid_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: 0,
        );
    }

    public function test_it_rejects_negative_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: -1,
        );
    }

    public function test_it_rejects_over_max_limits_for_direct_callers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be an integer between 1 and '.ListStudyBrowserAction::MAX_LIMIT.'.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            limit: ListStudyBrowserAction::MAX_LIMIT + 1,
        );
    }

    public function test_it_rejects_invalid_direct_cursors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Study browser cursor is invalid.');

        app(ListStudyBrowserAction::class)->handle(
            userId: $this->signIn()->id,
            cursor: 'not-a-cursor',
        );
    }
}
