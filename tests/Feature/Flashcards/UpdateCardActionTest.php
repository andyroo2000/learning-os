<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Actions\UpdateCardAction;
use App\Domain\Flashcards\Data\UpdateCardData;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsCardSyncFeedEntries;
use Tests\TestCase;

class UpdateCardActionTest extends TestCase
{
    use AssertsCardSyncFeedEntries;
    use RefreshDatabase;

    public function test_it_updates_card_text(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);
        $deck = Deck::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
        ]);
        $card = Card::factory()->for($deck)->create();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        );
        $updatedCard = $result->card->refresh();

        $this->assertTrue($result->wasUpdated);
        $this->assertSame($card->id, $updatedCard->id);

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $card->deck_id,
            'front_text' => 'arrivederci',
            'back_text' => 'goodbye',
        ]);

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($updatedCard, SyncFeedOperation::Update);

        $this->assertSame($course->id, $entry->payload['course_id']);
        $this->assertSame('arrivederci', $entry->payload['front_text']);
        $this->assertSame('goodbye', $entry->payload['back_text']);
    }

    public function test_it_updates_card_type(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'card_type' => CardType::Recognition,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'ciao',
                backText: 'hello',
                cardType: ' CLOZE ',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(CardType::Cloze, $result->card->card_type);
        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'card_type' => 'cloze',
        ]);
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Update);

        $this->assertSame('cloze', $entry->payload['card_type']);
    }

    public function test_it_updates_structured_content(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                hasPromptJson: true,
                promptJson: ['type' => 'text', 'text' => 'What is ATP?'],
                hasAnswerJson: true,
                answerJson: ['type' => 'text', 'text' => 'Cellular energy currency.'],
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $result->card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $result->card->answer_json);
        $this->assertSame(
            'What is ATP? Cellular energy currency. text What is ATP? text Cellular energy currency.',
            $result->card->search_text,
        );

        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Update);

        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $entry->payload['prompt_json']);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $entry->payload['answer_json']);
    }

    public function test_it_refreshes_automatic_concept_links_when_card_content_changes_and_preserves_manual_links(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '会社',
            'back_text' => 'Company',
        ]);
        $now = now();

        DB::table('card_learning_concepts')->insert([
            [
                'card_id' => $card->id,
                'concept_id' => 'n5-vocab-1198550-2120ff50',
                'match_method' => 'exact',
                'match_source' => 'backfill',
                'confidence' => 1,
                'classifier_version' => 'n5-rules-v1',
                'evidence' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'card_id' => $card->id,
                'concept_id' => 'n5-grammar-desu-polite-copula',
                'match_method' => 'manual',
                'match_source' => 'manual',
                'confidence' => 1,
                'classifier_version' => null,
                'evidence' => '{}',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(frontText: '学生', backText: 'Student'),
        );

        $this->assertDatabaseMissing('card_learning_concepts', [
            'card_id' => $card->id,
            'concept_id' => 'n5-vocab-1198550-2120ff50',
        ]);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $card->id,
            'concept_id' => 'n5-vocab-1206900-452102ad',
            'match_source' => 'creation',
            'classifier_version' => 'jlpt-n5-n4-rules-v5',
        ]);
        $this->assertDatabaseHas('card_learning_concepts', [
            'card_id' => $card->id,
            'concept_id' => 'n5-grammar-desu-polite-copula',
            'match_source' => 'manual',
        ]);
    }

    public function test_it_updates_structured_content_when_legacy_text_fields_are_omitted(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => '',
            'back_text' => '',
            'prompt_json' => ['cueText' => 'company'],
            'answer_json' => ['expression' => '会社'],
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '   ',
                backText: '   ',
                hasFrontText: false,
                hasBackText: false,
                hasAnswerJson: true,
                answerJson: ['expression' => '学校', 'meaning' => 'school'],
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('', $result->card->front_text);
        $this->assertSame('', $result->card->back_text);
        $this->assertSame(
            ['expression' => '学校', 'meaning' => 'school'],
            $result->card->answer_json,
        );
        $this->assertSame(
            'company 学校 school',
            $result->card->search_text,
        );

        $entry = $this->assertCardSyncPayloadRecorded(
            $result->card->refresh(),
            SyncFeedOperation::Update,
        );
        $this->assertSame('', $entry->payload['front_text']);
        $this->assertSame('', $entry->payload['back_text']);
        $this->assertSame('学校', $entry->payload['answer_json']['expression']);
    }

    public function test_it_clears_structured_content_when_explicit_nulls_are_provided(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'What is ATP?',
            'back_text' => 'Cellular energy currency.',
            'prompt_json' => ['type' => 'text', 'text' => 'What is ATP?'],
            'answer_json' => ['type' => 'text', 'text' => 'Cellular energy currency.'],
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'What is ATP?',
                backText: 'Cellular energy currency.',
                hasPromptJson: true,
                promptJson: null,
                hasAnswerJson: true,
                answerJson: null,
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertNull($result->card->prompt_json);
        $this->assertNull($result->card->answer_json);
        $this->assertSame('What is ATP? Cellular energy currency.', $result->card->search_text);
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Update);

        $this->assertNull($entry->payload['prompt_json']);
        $this->assertNull($entry->payload['answer_json']);
    }

    public function test_it_trims_text_inputs(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: '  arrivederci  ',
                backText: '  goodbye  ',
            ),
        );
        $updatedCard = $result->card;

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('arrivederci', $updatedCard->front_text);
        $this->assertSame('goodbye', $updatedCard->back_text);
    }

    public function test_it_derives_search_text_when_legacy_null_card_content_changes(): void
    {
        $card = $this->cardFor($this->signIn(), [
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        Card::query()
            ->whereKey($card->id)
            ->update(['search_text' => null]);
        $card->refresh();

        $result = app(UpdateCardAction::class)->handle(
            $card,
            UpdateCardData::fromInput(
                frontText: 'arrivederci',
                backText: 'goodbye',
            ),
        );

        $this->assertTrue($result->wasUpdated);
        $this->assertSame('arrivederci goodbye', $result->card->search_text);
        $this->assertDatabaseCount('sync_feed_entries', 1);

        $entry = $this->assertCardSyncPayloadRecorded($result->card->refresh(), SyncFeedOperation::Update);

        $this->assertSame('arrivederci goodbye', $entry->payload['search_text']);
    }
}
