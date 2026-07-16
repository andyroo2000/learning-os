<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Http\Resources\Study\StudyCardSummaryResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudyBrowserNoteDetailCompatibilityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_copied_convolab_notes_with_compatibility_identifiers(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => 'copied detail card',
            'source_note_id' => 500,
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->convolab_note_id = '9e33f12d-cf38-409b-bbf1-6fddd9977576';
        $card->save();

        $sharedSummary = StudyCardSummaryResource::make($card)->resolve(request());
        $this->assertSame($card->convolab_id, $sharedSummary['id']);
        $this->assertSame($card->convolab_note_id, $sharedSummary['noteId']);
        $this->assertSame('500', $sharedSummary['state']['source']['noteId']);
        $this->assertSame('日本語', $sharedSummary['state']['source']['deckName']);

        $this->getJson('/api/study/browser/9e33f12d-cf38-409b-bbf1-6fddd9977576')
            ->assertOk()
            ->assertJsonPath('noteId', '9e33f12d-cf38-409b-bbf1-6fddd9977576')
            ->assertJsonPath('selectedCardId', 'c358732a-2cd0-4b18-9cce-c474297863f9')
            ->assertJsonPath('cards.0.id', 'c358732a-2cd0-4b18-9cce-c474297863f9')
            ->assertJsonPath('cards.0.noteId', '9e33f12d-cf38-409b-bbf1-6fddd9977576')
            ->assertJsonPath('cards.0.state.source.noteId', '500')
            ->assertJsonPath('cards.0.state.source.deckName', '日本語')
            ->assertJsonPath('rawFields.0.name', 'frontText')
            ->assertJsonPath('rawFields.0.value', 'copied detail card')
            ->assertJsonPath('rawFields.1.name', 'backText')
            ->assertJsonPath('canonicalFields.0.name', 'displayText')
            ->assertJsonPath('canonicalFields.0.value', 'copied detail card')
            ->assertJsonPath('cardStats.0.cardId', 'c358732a-2cd0-4b18-9cce-c474297863f9');
    }

    public function test_it_preserves_copied_convolab_note_fields_and_source_metadata(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->make([
            'front_text' => 'fallback front',
            'back_text' => 'fallback back',
            'source_note_id' => 501,
            'source_card_id' => 701,
            'source_deck_id' => 801,
            'source_deck_name' => 'Japanese',
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 0,
            'source_template_name' => 'Card 1',
            'source_queue' => 2,
            'source_card_type' => 2,
            'source_due' => 12,
            'source_interval' => 30,
            'source_factor' => 2500,
            'source_reps' => 7,
            'source_lapses' => 1,
            'source_left' => 0,
            'source_original_due' => 4,
            'source_original_deck_id' => 901,
            'source_fsrs_json' => ['stability' => 4.2],
            'answer_audio_source' => 'generated',
        ]);
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->convolab_note_id = '9e33f12d-cf38-409b-bbf1-6fddd9977576';
        $card->convolab_note_source_kind = 'manual';
        $card->convolab_note_source_guid = 'anki-guid';
        $card->convolab_note_source_notetype_id = 601;
        $card->convolab_note_raw_fields_json = [
            'Expression' => '<b>会社</b>',
            'Count' => 0,
            'Empty' => '',
            'Notes' => "First line\nSecond line",
        ];
        $card->convolab_note_canonical_json = [
            'expression' => '会社',
            'metadata' => ['register' => 'formal'],
            'createdInApp' => true,
            'archived' => false,
        ];
        $card->save();

        $response = $this->getJson('/api/study/browser/9e33f12d-cf38-409b-bbf1-6fddd9977576')
            ->assertOk()
            ->assertJsonPath('sourceKind', 'manual')
            ->assertJsonPath('rawFields.0.name', 'Expression')
            ->assertJsonPath('rawFields.0.value', '<b>会社</b>')
            ->assertJsonPath('rawFields.0.textValue', '会社')
            ->assertJsonPath('rawFields.1.value', '0')
            ->assertJsonPath('rawFields.1.textValue', '0')
            ->assertJsonPath('rawFields.2.value', '')
            ->assertJsonPath('rawFields.2.textValue', null)
            ->assertJsonPath('rawFields.3.value', "First line\nSecond line")
            ->assertJsonPath('rawFields.3.textValue', "First line\nSecond line")
            ->assertJsonPath('canonicalFields.0.name', 'expression')
            ->assertJsonPath('canonicalFields.1.value', '{"register":"formal"}')
            ->assertJsonPath('canonicalFields.2.value', 'true')
            ->assertJsonPath('canonicalFields.2.textValue', 'true')
            ->assertJsonPath('canonicalFields.3.value', 'false')
            ->assertJsonPath('canonicalFields.3.textValue', 'false')
            ->assertJsonPath('cards.0.state.source.noteId', '501')
            ->assertJsonPath('cards.0.state.source.noteGuid', 'anki-guid')
            ->assertJsonPath('cards.0.state.source.deckName', 'Japanese')
            ->assertJsonPath('cards.0.state.source.notetypeId', '601')
            ->assertJsonPath('cards.0.state.source.templateName', 'Card 1')
            ->assertJsonPath('cards.0.state.source.queue', 2)
            ->assertJsonPath('cards.0.state.source.type', 2)
            ->assertJsonPath('cards.0.state.source.due', 12)
            ->assertJsonPath('cards.0.state.source.ivl', 30)
            ->assertJsonPath('cards.0.state.source.factor', 2500)
            ->assertJsonPath('cards.0.state.source.reps', 7)
            ->assertJsonPath('cards.0.state.source.lapses', 1)
            ->assertJsonPath('cards.0.state.source.left', 0)
            ->assertJsonPath('cards.0.state.source.odue', 4)
            ->assertJsonPath('cards.0.state.source.odid', '901')
            ->assertJsonPath('cards.0.state.rawFsrs.stability', 4.2)
            ->assertJsonPath('cards.0.answerAudioSource', 'generated');

        $this->assertArrayHasKey('textValue', $response->json('rawFields.2'));
    }

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
        $latestSecondCardReviewAt = $latestReviewAt->copy()->addMinute();

        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => now()->subDays(2),
        ]);
        CardReviewEvent::factory()->for($firstCard)->create([
            'reviewed_at' => $latestReviewAt,
        ]);
        CardReviewEvent::factory()->for($secondCard)->create([
            'reviewed_at' => $latestSecondCardReviewAt,
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

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
            ->assertJsonPath('reviewCount', 3)
            ->assertJsonPath('lastReviewedAt', $latestSecondCardReviewAt->toJSON())
            ->assertJsonPath('updatedAt', $secondCard->updated_at?->toJSON())
            ->assertJsonPath('selectedCardId', (string) $firstCard->id)
            ->assertJsonPath('cards.0.id', (string) $firstCard->id)
            ->assertJsonPath('cards.0.noteId', '501')
            ->assertJsonPath('cards.0.cardType', 'recognition')
            ->assertJsonPath('cards.1.id', (string) $secondCard->id)
            ->assertJsonPath('cards.1.cardType', 'production')
            ->assertJsonPath('rawFields.0.name', 'prompt.cueText')
            ->assertJsonPath('rawFields.0.value', '会社')
            ->assertJsonPath('rawFields.1.name', 'prompt.cueReading')
            ->assertJsonPath('rawFields.1.value', 'かいしゃ')
            ->assertJsonPath('rawFields.2.name', 'answer.meaning')
            ->assertJsonPath('rawFields.3.name', 'answer.expression')
            ->assertJsonPath('canonicalFields.0.name', 'displayText')
            ->assertJsonPath('canonicalFields.0.value', '会社')
            ->assertJsonPath('cardStats.0.cardId', (string) $firstCard->id)
            ->assertJsonPath('cardStats.0.reviewCount', 2)
            ->assertJsonPath('cardStats.0.lastReviewedAt', $latestReviewAt->toJSON())
            ->assertJsonPath('cardStats.1.cardId', (string) $secondCard->id)
            ->assertJsonPath('cardStats.1.reviewCount', 1)
            ->assertJsonPath('cardStats.1.lastReviewedAt', $latestSecondCardReviewAt->toJSON())
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

        $response = $this->getJson("/api/study/browser/{$card->id}")
            ->assertOk()
            ->assertJsonPath('noteId', (string) $card->id)
            ->assertJsonPath('displayText', 'unsourced card')
            ->assertJsonPath('reviewCount', 0)
            ->assertJsonPath('lastReviewedAt', null)
            ->assertJsonPath('rawFields.0.name', 'frontText')
            ->assertJsonPath('rawFields.0.value', 'unsourced card')
            ->assertJsonPath('rawFields.1.name', 'backText')
            ->assertJsonPath('rawFields.1.value', 'unsourced answer')
            ->assertJsonPath('cards.0.id', (string) $card->id)
            ->assertJsonPath('cards.0.noteId', null);

        $this->assertArrayHasKey('lastReviewedAt', $response->json());
    }

    public function test_it_exposes_media_fields_for_unsourced_fallback_text(): void
    {
        $user = $this->signIn();
        $card = Card::factory()->for($this->deckFor($user))->create([
            'front_text' => 'native prompt [sound:native-audio.mp3]',
            'back_text' => '<img alt="Native" src="native image.png">',
            'source_note_id' => null,
            'prompt_json' => null,
            'answer_json' => null,
        ]);

        $fieldsByName = $this->getJson("/api/study/browser/{$card->id}")
            ->assertOk()
            ->assertJsonPath('noteId', $card->id)
            ->assertJsonPath('rawFields.0.name', 'frontText')
            ->assertJsonPath('rawFields.0.value', 'native prompt [sound:native-audio.mp3]')
            ->assertJsonPath('rawFields.0.audio.filename', 'native-audio.mp3')
            ->assertJsonPath('rawFields.0.audio.mediaKind', 'audio')
            ->assertJsonPath('rawFields.1.name', 'backText')
            ->assertJsonPath('rawFields.1.value', '<img alt="Native" src="native image.png">')
            ->assertJsonPath('rawFields.1.image.filename', 'native image.png')
            ->assertJsonPath('rawFields.1.image.mediaKind', 'image')
            ->collect('rawFields')
            ->keyBy('name');

        $this->assertArrayHasKey('image', $fieldsByName['frontText']);
        $this->assertNull($fieldsByName['frontText']['image']);
        $this->assertArrayHasKey('audio', $fieldsByName['backText']);
        $this->assertNull($fieldsByName['backText']['audio']);
    }

    public function test_it_exposes_structured_and_legacy_media_fields_without_extra_queries(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);

        Card::factory()->for($deck)->create([
            'front_text' => 'fallback front',
            'back_text' => 'fallback back',
            'card_type' => CardType::Recognition,
            'source_kind' => 'anki_import',
            'source_note_id' => 601,
            'source_notetype_name' => 'Japanese - Media',
            'source_template_ord' => 0,
            'prompt_json' => [
                'cueAudio' => [
                    'id' => 'audio-1',
                    'filename' => 'word.mp3',
                    'url' => '/api/study/media/audio-1',
                    'mediaKind' => 'audio',
                    'source' => 'generated',
                ],
                'cueImage' => [
                    'id' => 'image-1',
                    'filename' => 'company.png',
                    'url' => '/api/study/media/image-1',
                    'mediaKind' => 'image',
                    'source' => 'imported_image',
                ],
            ],
            'answer_json' => [
                'legacyAudio' => '会社 [sound: word &amp; tone.mp3 ]',
                'legacyImage' => '<img alt="Company" src="company &amp; office.png">',
            ],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->getJson('/api/study/browser/601');
            $queries = collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $fieldsByName = $response
            ->assertOk()
            ->assertJsonPath('rawFields.0.name', 'prompt.cueAudio')
            ->assertJsonPath('rawFields.0.audio.id', 'audio-1')
            ->assertJsonPath('rawFields.0.audio.filename', 'word.mp3')
            ->assertJsonPath('rawFields.0.audio.mediaKind', 'audio')
            ->assertJsonPath('rawFields.0.image', null)
            ->assertJsonPath('rawFields.1.name', 'prompt.cueImage')
            ->assertJsonPath('rawFields.1.audio', null)
            ->assertJsonPath('rawFields.1.image.id', 'image-1')
            ->assertJsonPath('rawFields.1.image.filename', 'company.png')
            ->assertJsonPath('rawFields.1.image.mediaKind', 'image')
            ->assertJsonPath('rawFields.2.name', 'answer.legacyAudio')
            ->assertJsonPath('rawFields.2.audio.filename', 'word & tone.mp3')
            ->assertJsonPath('rawFields.2.audio.source', 'imported')
            ->assertJsonPath('rawFields.3.name', 'answer.legacyImage')
            ->assertJsonPath('rawFields.3.image.filename', 'company & office.png')
            ->assertJsonPath('rawFields.3.image.source', 'imported_image')
            ->collect('rawFields')
            ->keyBy('name');

        $this->assertSame(
            '{"id":"audio-1","filename":"word.mp3","url":"/api/study/media/audio-1","mediaKind":"audio","source":"generated"}',
            $fieldsByName['prompt.cueAudio']['value'],
            'Structured media fields should keep the legacy string value while exposing parsed media objects.',
        );
        $this->assertArrayHasKey('image', $fieldsByName['prompt.cueAudio']);
        $this->assertNull($fieldsByName['prompt.cueAudio']['image']);
        $this->assertArrayHasKey('audio', $fieldsByName['prompt.cueImage']);
        $this->assertNull($fieldsByName['prompt.cueImage']['audio']);

        $cardSelects = $queries->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select')
            && str_contains(strtolower($query['query']), 'from "cards"'));
        $this->assertCount(1, $cardSelects, 'Media field parsing should stay in-memory after the bounded card query.');
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
            ->assertJsonPath('noteId', (string) $card->id)
            ->assertJsonPath('selectedCardId', (string) $card->id)
            ->assertJsonPath('cards.0.id', (string) $card->id);
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
