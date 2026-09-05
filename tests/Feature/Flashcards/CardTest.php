<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            'convolab_id',
            'convolab_note_id',
            'convolab_note_created_at',
            'convolab_note_updated_at',
            'convolab_note_source_kind',
            'convolab_note_source_guid',
            'convolab_note_source_notetype_id',
            'convolab_note_raw_fields_json',
            'convolab_note_canonical_json',
            'deck_id',
            'import_job_id',
            'source_kind',
            'source_card_id',
            'source_note_id',
            'source_deck_id',
            'source_deck_name',
            'source_notetype_name',
            'source_template_ord',
            'source_template_name',
            'source_queue',
            'source_card_type',
            'source_due',
            'source_interval',
            'source_factor',
            'source_reps',
            'source_lapses',
            'source_left',
            'source_original_due',
            'source_original_deck_id',
            'source_fsrs_json',
            'answer_audio_source',
            'front_text',
            'back_text',
            'card_type',
            'prompt_json',
            'answer_json',
            'content_revision',
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
            'variant_unlock_requirement',
            'variant_unlocked_at',
            'variant_retired_at',
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
            'content_revision' => 0,
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
        $this->assertSame(0, $card->content_revision);
    }

    public function test_content_revision_advances_for_content_changes_but_not_study_state_changes(): void
    {
        $card = Card::factory()->create([
            'front_text' => '会社',
            'back_text' => 'company',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
        ]);

        $card->due_at = Carbon::parse('2026-09-01T12:00:00Z');
        $card->study_status = CardStudyStatus::Review;
        $card->saveOrFail();
        $this->assertSame(0, $card->refresh()->content_revision);

        $card->prompt_json = ['cueText' => '学校'];
        $card->saveOrFail();
        $this->assertSame(1, $card->refresh()->content_revision);

        $card->answer_audio_source = 'generated';
        $card->saveOrFail();
        $this->assertSame(2, $card->refresh()->content_revision);

        $card->variant_group_id = 'group-one';
        $card->variant_stage = 1;
        $card->saveOrFail();
        $this->assertSame(3, $card->refresh()->content_revision);
    }

    public function test_content_revision_cannot_be_assigned_directly_on_an_existing_card(): void
    {
        $card = Card::factory()->create();
        $card->content_revision = 9;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card content revision is server-owned.');

        $card->saveOrFail();
    }

    public function test_card_prefers_convolab_client_identifiers_without_overwriting_provenance(): void
    {
        $card = Card::factory()->make();
        $card->convolab_id = 'c358732a-2cd0-4b18-9cce-c474297863f9';
        $card->convolab_note_id = '9e33f12d-cf38-409b-bbf1-6fddd9977576';
        $card->source_note_id = 321;
        $card->save();

        $this->assertSame('c358732a-2cd0-4b18-9cce-c474297863f9', $card->clientId());
        $this->assertSame('9e33f12d-cf38-409b-bbf1-6fddd9977576', $card->clientNoteId());
        $this->assertSame(321, $card->source_note_id);
    }

    public function test_card_convolab_identifiers_are_immutable_after_create(): void
    {
        $card = Card::factory()->create();
        DB::table('cards')->where('id', $card->id)->update([
            'convolab_id' => 'c358732a-2cd0-4b18-9cce-c474297863f9',
        ]);
        $card->refresh();
        $card->convolab_id = '3bc53cee-82e0-4c18-b892-39c180801f22';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card ConvoLab compatibility metadata cannot be changed.');

        $card->save();
    }

    public function test_card_convolab_note_timestamps_are_immutable_after_create(): void
    {
        $card = Card::factory()->create();
        DB::table('cards')->where('id', $card->id)->update([
            'convolab_note_created_at' => '2026-06-01 12:00:00.123',
        ]);
        $card->refresh();
        $card->convolab_note_created_at = Carbon::parse('2026-06-02T12:00:00Z');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card ConvoLab compatibility metadata cannot be changed.');

        $card->save();
    }
}
