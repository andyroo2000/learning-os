<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListStudyExportCardsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/cards')->assertUnauthorized();
    }

    public function test_index_returns_current_cards_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $deck = $this->deckFor($user);
        $deletedDeck = $this->deckFor($user);
        $otherDeck = $this->deckFor(User::factory()->create());

        $firstCard = Card::factory()->for($deck)->create([
            'import_job_id' => strtolower((string) str()->ulid()),
            'source_kind' => 'anki_import',
            'source_card_id' => 701,
            'source_note_id' => 501,
            'source_deck_id' => 1700000000000,
            'source_notetype_name' => 'Basic',
            'source_template_ord' => 0,
            'front_text' => 'bonjour',
            'back_text' => 'hello',
            'card_type' => CardType::Recognition,
            'prompt_json' => ['kind' => 'text'],
            'answer_json' => ['kind' => 'text'],
            'study_status' => CardStudyStatus::New,
            'new_queue_position' => 1,
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => VocabVariantKind::SentenceAudioRecognition->value,
            'variant_stage' => 1,
            'variant_status' => VocabVariantStatus::Available->value,
            'variant_unlocked_at' => now(),
        ]);
        $secondCard = Card::factory()->for($deck)->create([
            'front_text' => 'merci',
            'back_text' => 'thanks',
            'card_type' => CardType::Production,
            'study_status' => CardStudyStatus::Review,
            'new_queue_position' => null,
            'due_at' => now()->addDay(),
        ]);
        $deletedCard = Card::factory()->for($deck)->create([
            'front_text' => 'deleted',
        ]);
        $cardInDeletedDeck = Card::factory()->for($deletedDeck)->create([
            'front_text' => 'hidden by deck tombstone',
        ]);
        $otherCard = Card::factory()->for($otherDeck)->create([
            'front_text' => 'hidden',
        ]);
        $mediaAsset = $this->mediaAssetForCardOwner($firstCard);

        $firstCard->mediaAssets()->attach($mediaAsset->id);

        $deletedCard->delete();
        DB::table('decks')
            ->where('id', $deletedDeck->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $this->getJson('/api/study/export/cards')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstCard->id)
            ->assertJsonPath('data.0.deck_id', $deck->id)
            ->assertJsonPath('data.0.course_id', $deck->course_id)
            ->assertJsonPath('data.0.import_job_id', $firstCard->import_job_id)
            ->assertJsonPath('data.0.source_kind', 'anki_import')
            ->assertJsonPath('data.0.source_card_id', 701)
            ->assertJsonPath('data.0.source_note_id', 501)
            ->assertJsonPath('data.0.source_deck_id', 1700000000000)
            ->assertJsonPath('data.0.source_notetype_name', 'Basic')
            ->assertJsonPath('data.0.source_template_ord', 0)
            ->assertJsonPath('data.0.front_text', 'bonjour')
            ->assertJsonPath('data.0.back_text', 'hello')
            ->assertJsonPath('data.0.card_type', CardType::Recognition->value)
            ->assertJsonPath('data.0.prompt_json.kind', 'text')
            ->assertJsonPath('data.0.answer_json.kind', 'text')
            ->assertJsonPath('data.0.study_status', CardStudyStatus::New->value)
            ->assertJsonPath('data.0.new_queue_position', 1)
            ->assertJsonPath('data.0.variant_group_id', 'vocab-group-1')
            ->assertJsonPath('data.0.variant_sentence_id', 'sentence-1')
            ->assertJsonPath('data.0.variant_kind', VocabVariantKind::SentenceAudioRecognition->value)
            ->assertJsonPath('data.0.variant_stage', 1)
            ->assertJsonPath('data.0.variant_status', VocabVariantStatus::Available->value)
            ->assertJsonPath('data.0.variant_unlocked_at', $firstCard->variant_unlocked_at->toJSON())
            ->assertJsonPath('data.0.deleted_at', null)
            ->assertJsonMissingPath('data.0.media_assets')
            ->assertJsonPath('data.1.id', $secondCard->id)
            ->assertJsonPath('data.1.import_job_id', null)
            ->assertJsonPath('data.1.source_kind', null)
            ->assertJsonPath('data.1.source_card_id', null)
            ->assertJsonPath('data.1.source_note_id', null)
            ->assertJsonPath('data.1.source_deck_id', null)
            ->assertJsonPath('data.1.source_notetype_name', null)
            ->assertJsonPath('data.1.source_template_ord', null)
            ->assertJsonPath('data.1.card_type', CardType::Production->value)
            ->assertJsonPath('data.1.study_status', CardStudyStatus::Review->value)
            ->assertJsonPath('data.1.variant_group_id', null)
            ->assertJsonPath('data.1.variant_status', null)
            ->assertJsonMissingPath('data.1.media_assets')
            ->assertJsonMissing([
                'id' => $deletedCard->id,
            ])
            ->assertJsonMissing([
                'id' => $cardInDeletedDeck->id,
            ])
            ->assertJsonMissing([
                'id' => $otherCard->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'deck_id',
                        'course_id',
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
                        'new_queue_position',
                        'scheduler_state',
                        'variant_group_id',
                        'variant_sentence_id',
                        'variant_kind',
                        'variant_stage',
                        'variant_status',
                        'variant_unlocked_at',
                        'due_at',
                        'introduced_at',
                        'failed_at',
                        'last_reviewed_at',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
            ]);
    }
}
