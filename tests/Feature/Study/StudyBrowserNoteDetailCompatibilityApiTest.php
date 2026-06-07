<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudyBrowserNoteDetailCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_browser_note_detail_grouped_by_source_note_id(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $firstCard = Card::factory()->for($deck)->create([
            'front_text' => 'fallback front',
            'back_text' => 'fallback back',
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'source_kind' => 'anki_import',
            'source_card_id' => 701,
            'source_note_id' => 501,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 0,
            'prompt_json' => [
                'cueText' => ' 会社 ',
                'cueReading' => 'かいしゃ',
            ],
            'answer_json' => [
                'meaning' => 'company',
            ],
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDay(),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'production fallback',
            'back_text' => 'answer fallback',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::New,
            'source_kind' => 'anki_import',
            'source_card_id' => 702,
            'source_note_id' => 501,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 1,
            'prompt_json' => [
                'cueText' => 'company',
            ],
            'answer_json' => [
                'expression' => '会社',
            ],
            'created_at' => now()->subDays(2),
            'updated_at' => now(),
        ]);
        Card::factory()->for($deck)->create([
            'front_text' => 'other note',
            'source_note_id' => 502,
        ]);
        $latestReviewAt = now()->subHour()->milliseconds(0);

        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => now()->subDays(2),
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => $latestReviewAt,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $response = $this->getJson('/api/study/browser/501');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response
            ->assertOk()
            ->assertJsonPath('noteId', '501')
            ->assertJsonPath('displayText', '会社')
            ->assertJsonPath('noteTypeName', 'Japanese - Vocab')
            ->assertJsonPath('sourceKind', 'anki_import')
            ->assertJsonPath('selectedCardId', $firstCard->id)
            ->assertJsonPath('cards.0.id', $firstCard->id)
            ->assertJsonPath('cards.0.noteId', '501')
            ->assertJsonPath('cards.0.cardType', 'recognition')
            ->assertJsonPath('cards.1.id', $secondCard->id)
            ->assertJsonPath('cards.1.cardType', 'production')
            ->assertJsonPath('rawFields.0.name', 'prompt.cueText')
            ->assertJsonPath('rawFields.0.value', '会社')
            ->assertJsonPath('rawFields.1.name', 'prompt.cueReading')
            ->assertJsonPath('rawFields.1.value', 'かいしゃ')
            ->assertJsonPath('rawFields.2.name', 'answer.meaning')
            ->assertJsonPath('rawFields.3.name', 'answer.expression')
            ->assertJsonPath('canonicalFields.0.name', 'displayText')
            ->assertJsonPath('canonicalFields.0.value', '会社')
            ->assertJsonPath('cardStats.0.cardId', $firstCard->id)
            ->assertJsonPath('cardStats.0.reviewCount', 2)
            ->assertJsonPath('cardStats.0.lastReviewedAt', $latestReviewAt->toJSON())
            ->assertJsonPath('cardStats.1.cardId', $secondCard->id)
            ->assertJsonPath('cardStats.1.reviewCount', 0)
            ->assertJsonCount(2, 'cards')
            ->assertJsonCount(4, 'rawFields')
            ->assertJsonCount(2, 'cardStats');

        $rawFieldNames = $response->collect('rawFields')->pluck('name');

        $this->assertSame(
            $rawFieldNames->unique()->values()->all(),
            $rawFieldNames->all(),
            'Study browser note detail should expose unique raw field names.',
        );
        $this->assertSame(
            ['会社'],
            $response->collect('rawFields')
                ->where('name', 'prompt.cueText')
                ->pluck('value')
                ->values()
                ->all(),
            'Study browser note detail should keep the first card value when raw field names collide.',
        );

        $cardSelects = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_contains(strtolower($query['query']), 'from "cards"'));
        $standaloneReviewStatsSelects = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_starts_with(strtolower($query['query']), 'select card_id, count(*) as review_count')
            && str_contains(strtolower($query['query']), 'from "card_review_events"'));
        $cardSelectsWithReviewStats = $cardSelects->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'review_events_count')
            && str_contains(strtolower($query['query']), 'review_events_max_reviewed_at')
            && str_contains(strtolower($query['query']), 'from "card_review_events"'));
        $filteredReviewStatsSelects = $cardSelectsWithReviewStats->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'where "card_id" in'));

        $this->assertCount(1, $cardSelects, 'Study browser note detail should load cards in one bounded query.');
        $this->assertCount(0, $standaloneReviewStatsSelects, 'Study browser note detail should not run a standalone review-stats query.');
        $this->assertCount(1, $cardSelectsWithReviewStats, 'Study browser note detail should load review stats in the card query.');
        $this->assertCount(1, $filteredReviewStatsSelects, 'Study browser note detail should filter review-stat aggregation to matching cards.');
    }

    public function test_it_uses_card_id_for_unsourced_note_detail(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'unsourced card',
            'back_text' => 'unsourced answer',
            'source_note_id' => null,
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $this->getJson("/api/study/browser/{$card->id}")
            ->assertOk()
            ->assertJsonPath('noteId', $card->id)
            ->assertJsonPath('displayText', 'unsourced card')
            ->assertJsonPath('rawFields.0.name', 'frontText')
            ->assertJsonPath('rawFields.0.value', 'unsourced card')
            ->assertJsonPath('rawFields.1.name', 'backText')
            ->assertJsonPath('rawFields.1.value', 'unsourced answer')
            ->assertJsonPath('cards.0.id', $card->id)
            ->assertJsonPath('cards.0.noteId', null);
    }

    public function test_it_resolves_lowercase_unsourced_card_note_ids(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'unsourced card',
            'source_note_id' => null,
        ]);

        $this
            ->getJson('/api/study/browser/'.strtolower($card->id))
            ->assertOk()
            ->assertJsonPath('noteId', $card->id)
            ->assertJsonPath('selectedCardId', $card->id)
            ->assertJsonPath('cards.0.id', $card->id);
    }

    public function test_it_returns_not_found_for_missing_deleted_or_cross_user_notes(): void
    {
        $user = $this->signIn();
        $deletedCard = Card::factory()->for($this->deckFor($user))->create([
            'source_note_id' => 9001,
        ]);
        $deletedDeck = $this->deckFor($user);
        Card::factory()->for($deletedDeck)->create([
            'source_note_id' => 9002,
        ]);
        Card::factory()->for($this->deckFor(User::factory()->create()))->create([
            'source_note_id' => 9003,
        ]);

        $deletedCard->delete();
        $deletedDeck->delete();

        $this->getJson('/api/study/browser/9001')->assertNotFound();
        $this->getJson('/api/study/browser/9002')->assertNotFound();
        $this->getJson('/api/study/browser/9003')->assertNotFound();
        $this->getJson('/api/study/browser/99999999')->assertNotFound();
        $this->getJson('/api/study/browser/999999999999999999999999999999')->assertNotFound();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/study/browser/501')
            ->assertUnauthorized();
    }
}
