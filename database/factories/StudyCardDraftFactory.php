<?php

namespace Database\Factories;

use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Enums\StudyManualCardDraftStatus;
use App\Domain\Study\Models\StudyCardDraft;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyCardDraft>
 */
class StudyCardDraftFactory extends Factory
{
    protected $model = StudyCardDraft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => StudyManualCardDraftStatus::Generating,
            'creation_kind' => StudyCardCreationKind::TextRecognition,
            'prompt_json' => ['cueText' => '犬'],
            'answer_json' => ['answerText' => 'dog'],
            'image_placement' => StudyCardImagePlacement::None,
            'image_prompt' => null,
            'preview_audio_json' => null,
            'preview_audio_role' => null,
            'preview_image_json' => null,
            'variant_group_id' => null,
            'variant_sentence_id' => null,
            'variant_kind' => null,
            'variant_stage' => null,
            'variant_status' => null,
            'variant_unlocked_at' => null,
            'error_message' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => StudyManualCardDraftStatus::Ready,
            'preview_audio_json' => [
                'id' => 'audio-1',
                'filename' => 'inu.mp3',
                'url' => '/api/study/media/audio-1',
                'mediaKind' => 'audio',
                'source' => 'generated',
            ],
            'preview_audio_role' => StudyCardAudioRole::Answer,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => StudyManualCardDraftStatus::Error,
            'error_message' => 'Could not fill the remaining fields.',
        ]);
    }
}
