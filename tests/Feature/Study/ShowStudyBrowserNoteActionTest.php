<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Actions\ShowStudyBrowserNoteAction;
use App\Domain\Study\Support\StudyBrowserCardAggregate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use UnexpectedValueException;

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

    public function test_it_exposes_media_fields_for_direct_callers(): void
    {
        $user = $this->signIn();
        Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'media detail [sound:canonical.mp3]',
            'source_note_id' => 7101,
            'prompt_json' => [
                'cueAudio' => [
                    'id' => 'audio-1',
                    'filename' => 'word.mp3',
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
            ],
            'answer_json' => [
                'legacyImage' => '<img src="company.png">',
            ],
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, '7101');

        $this->assertNotNull($result);

        $fieldsByName = collect($result->rawFields)->keyBy('name');

        $this->assertSame('audio-1', $fieldsByName['prompt.cueAudio']['audio']['id']);
        $this->assertSame('word.mp3', $fieldsByName['prompt.cueAudio']['audio']['filename']);
        $this->assertArrayHasKey('image', $fieldsByName['prompt.cueAudio']);
        $this->assertNull($fieldsByName['prompt.cueAudio']['image']);
        $this->assertSame('company.png', $fieldsByName['answer.legacyImage']['image']['filename']);
        $this->assertSame('imported_image', $fieldsByName['answer.legacyImage']['image']['source']);
        $this->assertSame('displayText', $result->canonicalFields[0]['name']);
        $this->assertSame('canonical.mp3', $result->canonicalFields[0]['audio']['filename']);
        $this->assertArrayHasKey('image', $result->canonicalFields[0]);
        $this->assertNull($result->canonicalFields[0]['image']);
    }

    public function test_it_normalizes_database_timestamp_strings_from_review_aggregates(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'aggregate detail card',
            'source_note_id' => 7002,
        ]);
        $reviewEvent = CardReviewEvent::factory()->for($card)->create([
            'reviewed_at' => Carbon::parse('2026-06-04T10:00:00Z'),
        ]);

        DB::table('card_review_events')
            ->where('id', $reviewEvent->id)
            ->update(['reviewed_at' => '2026-06-04 11:00:00']);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, '7002');

        $this->assertNotNull($result);
        $this->assertSame('2026-06-04T11:00:00.000000Z', $result->cardStats[0]['lastReviewedAt']);
    }

    public function test_it_reports_note_review_summary_when_only_a_sibling_card_was_reviewed(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'unreviewed detail prompt',
            'source_note_id' => 7003,
            'source_template_ord' => 0,
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'reviewed detail answer',
            'source_note_id' => 7003,
            'source_template_ord' => 1,
        ]);
        CardReviewEvent::factory()->for($secondCard)->create([
            'reviewed_at' => Carbon::parse('2026-06-05T12:00:00Z'),
        ]);

        $result = app(ShowStudyBrowserNoteAction::class)->handle($user->id, '7003');

        $this->assertNotNull($result);
        $this->assertSame((string) $firstCard->id, $result->selectedCardId);
        $this->assertSame(1, $result->reviewCount);
        $this->assertSame('2026-06-05T12:00:00.000000Z', $result->lastReviewedAt);
        $this->assertSame((string) $firstCard->id, $result->cardStats[0]['cardId']);
        $this->assertSame(0, $result->cardStats[0]['reviewCount']);
        $this->assertNull($result->cardStats[0]['lastReviewedAt']);
        $this->assertSame((string) $secondCard->id, $result->cardStats[1]['cardId']);
        $this->assertSame(1, $result->cardStats[1]['reviewCount']);
        $this->assertSame('2026-06-05T12:00:00.000000Z', $result->cardStats[1]['lastReviewedAt']);
    }

    public function test_it_rejects_rows_without_updated_timestamps_for_direct_callers(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'missing updated timestamp card',
            'source_note_id' => 7004,
        ]);
        DB::table('cards')
            ->where('id', $card->id)
            ->update(['updated_at' => null]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study browser updated_at timestamp is missing or invalid.');

        app(ShowStudyBrowserNoteAction::class)->handle($user->id, '7004');
    }

    public function test_it_normalizes_native_datetime_review_aggregates(): void
    {
        $this->assertSame(
            '2026-06-04T11:00:00.000000Z',
            StudyBrowserCardAggregate::reviewAggregateTimestamp(new DateTimeImmutable('2026-06-04T11:00:00Z')),
        );
    }

    public function test_it_rejects_unexpected_review_aggregate_timestamp_types(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Study browser review aggregate has an unexpected timestamp type.');

        StudyBrowserCardAggregate::reviewAggregateTimestamp(123);
    }

    public function test_it_returns_null_for_malformed_unsourced_note_ids(): void
    {
        $user = $this->signIn();
        DB::enableQueryLog();
        DB::flushQueryLog();

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
