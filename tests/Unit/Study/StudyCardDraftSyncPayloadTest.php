<?php

namespace Tests\Unit\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Enums\StudyVocabVariantKind;
use App\Domain\Study\Enums\StudyVocabVariantStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class StudyCardDraftSyncPayloadTest extends TestCase
{
    public function test_draft_payload_uses_client_facing_resource_keys(): void
    {
        $draft = new StudyCardDraft;
        $draft->setRawAttributes([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'status' => StudyManualCardDraftStatus::Ready->value,
            'creation_kind' => StudyCardCreationKind::ProductionImage->value,
            'card_type' => CardType::Production->value,
            'prompt_json' => json_encode(['cueText' => '会社']),
            'answer_json' => json_encode(['meaning' => 'company']),
            'image_placement' => StudyCardImagePlacement::Prompt->value,
            'image_prompt' => 'A small office building',
            'preview_audio_json' => json_encode([
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ]),
            'preview_audio_role' => StudyCardAudioRole::Answer->value,
            'preview_image_json' => null,
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => StudyVocabVariantKind::SentenceCloze->value,
            'variant_stage' => 5,
            'variant_status' => StudyVocabVariantStatus::Locked->value,
            'variant_unlocked_at' => Carbon::parse('2026-06-04T14:15:00Z'),
            'error_message' => null,
            'committed_card_id' => '01ktt2q9z5vfpxsqgc3mwrdh36',
            'created_at' => Carbon::parse('2026-06-03T14:15:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T14:15:00Z'),
        ], sync: true);

        $payload = StudyCardDraftSyncPayload::fromDraft($draft);

        $this->assertSame('study', StudyCardDraftSyncPayload::DOMAIN);
        $this->assertSame('study_card_draft', StudyCardDraftSyncPayload::RESOURCE_TYPE);
        $this->assertSame([
            'id' => '01ktt2q9z5vfpxsqgc3mwrdh35',
            'status' => 'ready',
            'creation_kind' => 'production-image',
            'card_type' => 'production',
            'prompt_json' => ['cueText' => '会社'],
            'answer_json' => ['meaning' => 'company'],
            'image_placement' => 'prompt',
            'image_prompt' => 'A small office building',
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'kaisha.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => 'answer',
            'preview_image_json' => null,
            'variant_group_id' => 'vocab-group-1',
            'variant_sentence_id' => 'sentence-1',
            'variant_kind' => 'sentence_cloze',
            'variant_stage' => 5,
            'variant_status' => 'locked',
            'variant_unlocked_at' => '2026-06-04T14:15:00.000000Z',
            'error_message' => null,
            'committed_card_id' => '01ktt2q9z5vfpxsqgc3mwrdh36',
            'created_at' => '2026-06-03T14:15:00.000000Z',
            'updated_at' => '2026-06-04T14:15:00.000000Z',
            'deleted_at' => null,
        ], $payload);
    }
}
