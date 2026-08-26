<?php

namespace Tests\Feature\Contracts;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Http\Resources\Study\StudyCardSummaryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\Contracts\CompatibilityFixtureRepository;
use Tests\TestCase;

class StudyCardSummaryContractFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_default_card_matches_the_canonical_fixture(): void
    {
        $card = Card::factory()->for($this->deckFor($this->signIn()))->create([
            'id' => '01J60000000000000000000001',
            'front_text' => '聞く',
            'back_text' => 'to listen',
            'card_type' => CardType::Recognition,
            'prompt_json' => null,
            'answer_json' => null,
            'study_status' => CardStudyStatus::New,
            'scheduler_state' => null,
            'source_note_id' => null,
            'source_deck_name' => null,
            'created_at' => Carbon::parse('2026-08-20T10:11:12Z'),
            'updated_at' => Carbon::parse('2026-08-20T10:11:13Z'),
        ]);

        $this->assertSame(
            CompatibilityFixtureRepository::case('study-card-summary.v1', 'native-defaults')['payload'],
            StudyCardSummaryResource::make($card->fresh())->resolve(request()),
        );
    }

    public function test_imported_progression_card_matches_the_canonical_fixture(): void
    {
        $card = Card::factory()->for($this->deckFor($this->signIn()))->create([
            'id' => '01J60000000000000000000002',
            'convolab_id' => 'c358732a-2cd0-4b18-9cce-c474297863f9',
            'convolab_note_id' => '9e33f12d-cf38-409b-bbf1-6fddd9977576',
            'front_text' => '会社',
            'back_text' => 'company',
            'card_type' => CardType::Production,
            'prompt_json' => [
                'type' => 'text',
                'text' => 'company',
            ],
            'answer_json' => [
                'type' => 'text',
                'text' => '会社',
                'answerAudio' => [
                    'url' => '/media/company.mp3',
                    'source' => 'imported',
                ],
            ],
            'study_status' => CardStudyStatus::Review,
            'due_at' => Carbon::parse('2026-09-01T12:00:00Z'),
            'introduced_at' => Carbon::parse('2026-07-01T08:30:00Z'),
            'failed_at' => Carbon::parse('2026-07-02T09:45:00Z'),
            'scheduler_state' => [
                'stability' => 45.5,
                'difficulty' => 3.25,
                'reps' => 12,
            ],
            'source_note_id' => 501,
            'source_card_id' => 701,
            'source_deck_id' => 801,
            'source_deck_name' => 'Japanese',
            'convolab_note_source_guid' => 'anki-guid',
            'convolab_note_source_notetype_id' => 601,
            'source_notetype_name' => 'Japanese - Vocab',
            'source_template_ord' => 1,
            'source_template_name' => 'Production',
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
            'source_fsrs_json' => ['stability' => 4.2, 'retrievability' => null],
            'variant_group_id' => 'vocab-company',
            'variant_sentence_id' => 'sentence-company-1',
            'variant_kind' => VocabVariantKind::SentenceTextRecognition->value,
            'variant_stage' => 3,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => Carbon::parse('2026-07-03T10:00:00Z'),
            'variant_retired_at' => Carbon::parse('2026-08-03T10:00:00Z'),
            'introduction_available_at' => Carbon::parse('2026-08-27T10:00:00Z'),
            'answer_audio_source' => 'imported',
            'created_at' => Carbon::parse('2026-06-01T01:02:03Z'),
            'updated_at' => Carbon::parse('2026-08-21T04:05:06Z'),
        ]);

        $this->assertSame(
            CompatibilityFixtureRepository::case('study-card-summary.v1', 'imported-progression')['payload'],
            StudyCardSummaryResource::make($card->fresh())->resolve(request()),
        );
    }
}
