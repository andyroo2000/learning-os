<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Flashcards\Enums\CardProgressionUnlockRequirement;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CardAttributeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_casts_card_type_structured_content_and_study_state_fields(): void
    {
        [$card, $dueAt] = $this->cardWithStructuredStudyState();

        $this->assertStructuredStudyStateCasts($card, $dueAt);
    }

    public function test_last_reviewed_at_setter_preserves_milliseconds_and_detects_within_second_changes(): void
    {
        $card = Card::factory()->create();

        $card->setLastReviewedAt(Carbon::parse('2026-06-03T14:15:00.123999Z'));

        $this->assertTrue($card->isDirty('last_reviewed_at'));

        $card->saveOrFail();
        $card->refresh();

        $this->assertSame('2026-06-03T14:15:00.123000Z', $card->last_reviewed_at?->toJSON());

        $card->setLastReviewedAt(Carbon::parse('2026-06-03T14:15:00.123000Z'));

        $this->assertFalse($card->isDirty('last_reviewed_at'));

        $card->setLastReviewedAt(Carbon::parse('2026-06-03T14:15:00.456999Z'));

        $this->assertTrue($card->isDirty('last_reviewed_at'));

        $card->saveOrFail();
        $card->refresh();

        $this->assertSame('2026-06-03T14:15:00.456000Z', $card->last_reviewed_at?->toJSON());
    }

    public function test_server_owned_card_state_and_import_source_fields_are_not_mass_assignable(): void
    {
        $card = $this->cardWithAttemptedServerOwnedAssignments();

        $this->assertServerOwnedAssignmentsWereIgnored($card);
    }

    /**
     * @return array{0: Card, 1: Carbon}
     */
    private function cardWithStructuredStudyState(): array
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
        $card->source_queue = '2';
        $card->source_card_type = '1';
        $card->source_due = '42';
        $card->source_interval = '30';
        $card->source_factor = '2500';
        $card->source_reps = '10';
        $card->source_lapses = '2';
        $card->source_left = '0';
        $card->source_original_due = '12';
        $card->source_original_deck_id = '1700000000004';
        $card->source_fsrs_json = ['stability' => 4.2];
        $card->variant_kind = VocabVariantKind::WordAudioRecognition->value;
        $card->variant_stage = '3';
        $card->variant_status = VocabVariantStatus::Locked->value;
        $card->variant_unlocked_at = Carbon::parse('2026-06-04T14:15:00Z');
        $card->save();
        DB::table('cards')->where('id', $card->id)->update([
            'convolab_note_source_notetype_id' => '1700000000005',
            'convolab_note_raw_fields_json' => json_encode(['Expression' => '会社']),
            'convolab_note_canonical_json' => json_encode(['expression' => '会社']),
        ]);
        $card->refresh();

        return [$card, $dueAt];
    }

    private function assertStructuredStudyStateCasts(Card $card, Carbon $dueAt): void
    {
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
        $this->assertSame(2, $card->source_queue);
        $this->assertSame(1, $card->source_card_type);
        $this->assertSame(42, $card->source_due);
        $this->assertSame(30, $card->source_interval);
        $this->assertSame(2500, $card->source_factor);
        $this->assertSame(10, $card->source_reps);
        $this->assertSame(2, $card->source_lapses);
        $this->assertSame(0, $card->source_left);
        $this->assertSame(12, $card->source_original_due);
        $this->assertSame(1700000000004, $card->source_original_deck_id);
        $this->assertSame(['stability' => 4.2], $card->source_fsrs_json);
        $this->assertSame(1700000000005, $card->convolab_note_source_notetype_id);
        $this->assertSame(['Expression' => '会社'], $card->convolab_note_raw_fields_json);
        $this->assertSame(['expression' => '会社'], $card->convolab_note_canonical_json);
        $this->assertSame(VocabVariantKind::WordAudioRecognition->value, $card->variant_kind);
        $this->assertSame(3, $card->variant_stage);
        $this->assertSame(VocabVariantStatus::Locked->value, $card->variant_status);
        $this->assertSame('2026-06-04T14:15:00.000000Z', $card->variant_unlocked_at->toJSON());
    }

    private function cardWithAttemptedServerOwnedAssignments(): Card
    {
        $card = new Card([
            'deck_id' => strtolower((string) Str::ulid()),
            'front_text' => 'ciao',
            'back_text' => 'hello',
            'import_job_id' => strtolower((string) Str::ulid()),
            'convolab_id' => 'c358732a-2cd0-4b18-9cce-c474297863f9',
            'convolab_note_id' => '9e33f12d-cf38-409b-bbf1-6fddd9977576',
            'convolab_note_created_at' => Carbon::parse('2026-06-01T14:15:00Z'),
            'convolab_note_updated_at' => Carbon::parse('2026-06-02T14:15:00Z'),
            'convolab_note_source_kind' => 'anki_import',
            'convolab_note_source_guid' => 'source-guid',
            'convolab_note_source_notetype_id' => 1700000000004,
            'convolab_note_raw_fields_json' => ['Expression' => '会社'],
            'convolab_note_canonical_json' => ['expression' => '会社'],
            'source_kind' => 'anki_import',
            'source_card_id' => 1700000000001,
            'source_note_id' => 1700000000002,
            'source_deck_id' => 1700000000003,
            'source_deck_name' => 'Japanese',
            'source_notetype_name' => 'Basic',
            'source_template_ord' => 1,
            'source_template_name' => 'Card 1',
            'source_queue' => 2,
            'source_card_type' => 1,
            'source_due' => 42,
            'source_interval' => 30,
            'source_factor' => 2500,
            'source_reps' => 10,
            'source_lapses' => 2,
            'source_left' => 0,
            'source_original_due' => 12,
            'source_original_deck_id' => 1700000000005,
            'source_fsrs_json' => ['stability' => 4.2],
            'answer_audio_source' => 'generated',
            'content_revision' => 99,
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
            'variant_unlock_requirement' => CardProgressionUnlockRequirement::Master->value,
            'variant_unlocked_at' => Carbon::parse('2026-06-04T14:15:00Z'),
            'search_text' => 'client-owned text',
        ]);

        return $card;
    }

    private function assertServerOwnedAssignmentsWereIgnored(Card $card): void
    {
        $this->assertSame(CardStudyStatus::New, $card->study_status);
        $this->assertNull($card->due_at);
        $this->assertNull($card->introduced_at);
        $this->assertNull($card->failed_at);
        $this->assertNull($card->last_reviewed_at);
        $this->assertNull($card->new_queue_position);
        $this->assertNull($card->scheduler_state);
        $this->assertSame('', $card->search_text);
        $this->assertNull($card->import_job_id);
        $this->assertNull($card->convolab_id);
        $this->assertNull($card->convolab_note_id);
        $this->assertNull($card->convolab_note_created_at);
        $this->assertNull($card->convolab_note_updated_at);
        $this->assertNull($card->convolab_note_source_kind);
        $this->assertNull($card->convolab_note_source_guid);
        $this->assertNull($card->convolab_note_source_notetype_id);
        $this->assertNull($card->convolab_note_raw_fields_json);
        $this->assertNull($card->convolab_note_canonical_json);
        $this->assertNull($card->source_kind);
        $this->assertNull($card->source_card_id);
        $this->assertNull($card->source_note_id);
        $this->assertNull($card->source_deck_id);
        $this->assertNull($card->source_deck_name);
        $this->assertNull($card->source_notetype_name);
        $this->assertNull($card->source_template_ord);
        $this->assertNull($card->source_template_name);
        $this->assertNull($card->source_queue);
        $this->assertNull($card->source_card_type);
        $this->assertNull($card->source_due);
        $this->assertNull($card->source_interval);
        $this->assertNull($card->source_factor);
        $this->assertNull($card->source_reps);
        $this->assertNull($card->source_lapses);
        $this->assertNull($card->source_left);
        $this->assertNull($card->source_original_due);
        $this->assertNull($card->source_original_deck_id);
        $this->assertNull($card->source_fsrs_json);
        $this->assertNull($card->answer_audio_source);
        $this->assertSame(0, $card->content_revision);
        $this->assertNull($card->variant_group_id);
        $this->assertNull($card->variant_sentence_id);
        $this->assertNull($card->variant_kind);
        $this->assertNull($card->variant_stage);
        $this->assertNull($card->variant_status);
        $this->assertNull($card->variant_unlock_requirement);
        $this->assertNull($card->variant_unlocked_at);
    }
}
