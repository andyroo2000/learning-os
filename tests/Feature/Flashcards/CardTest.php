<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class CardTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('cards', [
            'id',
            'deck_id',
            'import_job_id',
            'source_kind',
            'source_card_id',
            'source_note_id',
            'source_deck_id',
            'source_notetype_name',
            'source_template_ord',
            'front_text',
            'back_text',
            'card_type',
            'prompt_json',
            'answer_json',
            'search_text',
            'study_status',
            'due_at',
            'introduced_at',
            'failed_at',
            'last_reviewed_at',
            'new_queue_position',
            'scheduler_state',
            'variant_group_id',
            'variant_sentence_id',
            'variant_kind',
            'variant_stage',
            'variant_status',
            'variant_unlocked_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_card_can_be_created_with_a_deck(): void
    {
        $deck = Deck::factory()->create();

        $card = Card::factory()->create([
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->assertIsString($card->id);
        $this->assertTrue(Str::isUlid($card->id));

        $this->assertDatabaseHas('cards', [
            'id' => $card->id,
            'deck_id' => $deck->id,
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'card_type' => 'recognition',
            'prompt_json' => null,
            'answer_json' => null,
            'search_text' => 'ciao hello',
        ]);
    }

    public function test_new_cards_default_to_recognition_type_and_new_study_status_without_schedule_dates(): void
    {
        $card = Card::factory()->create();

        $this->assertSame(CardType::Recognition, $card->card_type);
        $this->assertSame(CardStudyStatus::New, $card->study_status);
        $this->assertNull($card->due_at);
        $this->assertNull($card->introduced_at);
        $this->assertNull($card->failed_at);
        $this->assertNull($card->last_reviewed_at);
        $this->assertNull($card->new_queue_position);
        $this->assertNull($card->scheduler_state);
    }

    public function test_card_casts_card_type_structured_content_and_study_state_fields(): void
    {
        $dueAt = Carbon::parse('2026-06-05T14:15:00Z');

        $card = Card::factory()->create();
        $card->card_type = CardType::Production;
        $card->prompt_json = ['type' => 'text', 'text' => 'What is ATP?'];
        $card->answer_json = ['type' => 'text', 'text' => 'Cellular energy currency.'];
        $card->study_status = CardStudyStatus::Review;
        $card->due_at = $dueAt;
        $card->introduced_at = Carbon::parse('2026-06-01T14:15:00Z');
        $card->failed_at = Carbon::parse('2026-06-02T14:15:00Z');
        $card->last_reviewed_at = Carbon::parse('2026-06-03T14:15:00Z');
        $card->new_queue_position = '7';
        $card->scheduler_state = [
            'difficulty' => 5,
            'stability' => 0.1,
            'state' => 0,
        ];
        $card->source_card_id = '1700000000001';
        $card->source_note_id = '1700000000002';
        $card->source_deck_id = '1700000000003';
        $card->source_template_ord = '2';
        $card->variant_kind = VocabVariantKind::WordAudioRecognition->value;
        $card->variant_stage = '3';
        $card->variant_status = VocabVariantStatus::Locked->value;
        $card->variant_unlocked_at = Carbon::parse('2026-06-04T14:15:00Z');
        $card->save();
        $card->refresh();

        $this->assertSame(CardType::Production, $card->card_type);
        $this->assertSame(['type' => 'text', 'text' => 'What is ATP?'], $card->prompt_json);
        $this->assertSame(['type' => 'text', 'text' => 'Cellular energy currency.'], $card->answer_json);
        $this->assertSame(CardStudyStatus::Review, $card->study_status);
        $this->assertSame($dueAt->toJSON(), $card->due_at?->toJSON());
        $this->assertSame('2026-06-01T14:15:00.000000Z', $card->introduced_at?->toJSON());
        $this->assertSame('2026-06-02T14:15:00.000000Z', $card->failed_at?->toJSON());
        $this->assertSame('2026-06-03T14:15:00.000000Z', $card->last_reviewed_at?->toJSON());
        $this->assertSame(7, $card->new_queue_position);
        $this->assertSame([
            'difficulty' => 5,
            'stability' => 0.1,
            'state' => 0,
        ], $card->scheduler_state);
        $this->assertSame(1700000000001, $card->source_card_id);
        $this->assertSame(1700000000002, $card->source_note_id);
        $this->assertSame(1700000000003, $card->source_deck_id);
        $this->assertSame(2, $card->source_template_ord);
        $this->assertSame(VocabVariantKind::WordAudioRecognition->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $card->variant_status);
        $this->assertSame('2026-06-04T14:15:00.000000Z', $card->variant_unlocked_at->toJSON());
    }

    public function test_server_owned_card_state_and_import_source_fields_are_not_mass_assignable(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'import_job_id' => strtolower((string) Str::ulid()),
            'source_kind' => 'anki_import',
            'source_card_id' => 1700000000001,
            'source_note_id' => 1700000000002,
            'source_deck_id' => 1700000000003,
            'source_notetype_name' => 'Basic',
            'source_template_ord' => 1,
            'study_status' => CardStudyStatus::Review,
            'due_at' => Carbon::parse('2026-06-05T14:15:00Z'),
            'introduced_at' => Carbon::parse('2026-06-01T14:15:00Z'),
            'failed_at' => Carbon::parse('2026-06-02T14:15:00Z'),
            'last_reviewed_at' => Carbon::parse('2026-06-03T14:15:00Z'),
            'new_queue_position' => 7,
            'scheduler_state' => ['state' => 0],
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::WordTextRecognition->value,
            'variant_stage' => 4,
            'variant_status' => VocabVariantStatus::Locked->value,
            'variant_unlocked_at' => Carbon::parse('2026-06-04T14:15:00Z'),
            'search_text' => 'client-owned text',
        ]);

        $this->assertSame(CardStudyStatus::New, $card->study_status);
        $this->assertNull($card->due_at);
        $this->assertNull($card->introduced_at);
        $this->assertNull($card->failed_at);
        $this->assertNull($card->last_reviewed_at);
        $this->assertNull($card->new_queue_position);
        $this->assertNull($card->scheduler_state);
        $this->assertSame('', $card->search_text);
        $this->assertNull($card->import_job_id);
        $this->assertNull($card->source_kind);
        $this->assertNull($card->source_card_id);
        $this->assertNull($card->source_note_id);
        $this->assertNull($card->source_deck_id);
        $this->assertNull($card->source_notetype_name);
        $this->assertNull($card->source_template_ord);
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlocked_at);
    }

    public function test_card_belongs_to_a_deck(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $this->assertTrue($card->deck->is($deck));
        $this->assertTrue($deck->cards->contains($card));
    }

    public function test_owner_user_id_fails_when_parent_deck_cannot_be_resolved(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_loaded_deck_has_no_owner(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRelation('deck', new Deck);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_null(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => null,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_zero(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => 0,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_negative(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => -1,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_empty(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => '',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_fails_when_selected_owner_attribute_is_a_malformed_numeric_string(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
        ]);
        $card->setRawAttributes([
            'deck_id' => $card->deck_id,
            'front_text' => $card->front_text,
            'back_text' => $card->back_text,
            'owner_user_id' => '3abc',
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        $card->ownerUserId();
    }

    public function test_owner_user_id_resolves_soft_deleted_parent_decks(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertSame($deck->user_id, $card->ownerUserId());
    }

    public function test_owner_user_id_uses_a_selected_owner_attribute(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $queriedCard = Card::query()
            ->select('cards.*')
            ->selectRaw('decks.user_id as owner_user_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereKey($card->id)
            ->sole();

        $this->assertSame($deck->user_id, $queriedCard->ownerUserId());
    }

    public function test_card_can_be_soft_deleted(): void
    {
        $card = Card::factory()->create();

        $card->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_cards_are_soft_deleted_when_their_deck_is_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();

        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);

        $deletedDeck = Deck::withTrashed()->findOrFail($deck->id);
        $deletedCard = Card::withTrashed()->findOrFail($card->id);

        $this->assertSame(
            $deletedDeck->deleted_at?->toJSON(),
            $deletedCard->deleted_at?->toJSON(),
        );
    }

    public function test_restoring_a_deck_leaves_cascade_deleted_cards_soft_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->delete();
        $deck->restore();

        $this->assertDatabaseHas('decks', [
            'id' => $deck->id,
            'deleted_at' => null,
        ]);
        $this->assertSoftDeleted('cards', [
            'id' => $card->id,
        ]);
    }

    public function test_deck_soft_delete_preserves_independently_deleted_cards_original_timestamp(): void
    {
        $deck = Deck::factory()->create();
        $independentlyDeletedCard = Card::factory()->create(['deck_id' => $deck->id]);
        $activeCard = Card::factory()->create(['deck_id' => $deck->id]);

        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        try {
            $independentlyDeletedCard->delete();
            $originalDeletedAt = $independentlyDeletedCard->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:01'));

            $deck->delete();

            $this->assertSoftDeleted('cards', [
                'id' => $activeCard->id,
            ]);

            $this->assertDatabaseHas('cards', [
                'id' => $independentlyDeletedCard->id,
                'deleted_at' => $originalDeletedAt,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_redeleting_a_soft_deleted_deck_does_not_retimestamp_cascade_deleted_cards(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        try {
            $deck->delete();
            $originalDeletedAt = $card->refresh()->deleted_at;

            Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:01'));

            Deck::withTrashed()->findOrFail($deck->id)->delete();

            $this->assertDatabaseHas('cards', [
                'id' => $card->id,
                'deleted_at' => $originalDeletedAt,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cards_are_deleted_when_their_deck_is_force_deleted(): void
    {
        $deck = Deck::factory()->create();
        $card = Card::factory()->create(['deck_id' => $deck->id]);

        $deck->forceDelete();

        $this->assertDatabaseMissing('cards', [
            'id' => $card->id,
        ]);
    }
}
