<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Actions\BuildStudyOfflineReserveAction;
use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Domain\Study\Enums\StudyCardAudioRole;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Models\StudySettings;

final class StudyClientCapabilities
{
    public const CONTRACT_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::CONTRACT_VERSION,
            'settings' => [
                'newCardsPerDay' => $this->integerRange(
                    StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
                    StudySettings::MIN_NEW_CARDS_PER_DAY,
                    StudySettings::MAX_NEW_CARDS_PER_DAY,
                ),
                'lessonBatchSize' => $this->integerRange(
                    StudySettings::DEFAULT_LESSON_BATCH_SIZE,
                    StudySettings::MIN_LESSON_BATCH_SIZE,
                    StudySettings::MAX_LESSON_BATCH_SIZE,
                ),
                'reviewTimeBudgetMinutes' => $this->integerRange(
                    StudySettings::DEFAULT_REVIEW_TIME_BUDGET_MINUTES,
                    StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES,
                    StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
                ),
                'newCardLaneWeights' => [
                    'standard' => $this->integerRange(
                        StudySettings::DEFAULT_STANDARD_LANE_WEIGHT,
                        StudySettings::MIN_STANDARD_LANE_WEIGHT,
                        StudySettings::MAX_LANE_WEIGHT,
                    ),
                    'lessonFollowup' => $this->integerRange(
                        StudySettings::DEFAULT_LESSON_FOLLOWUP_LANE_WEIGHT,
                        StudySettings::MIN_PRIORITY_LANE_WEIGHT,
                        StudySettings::MAX_LANE_WEIGHT,
                    ),
                    'wanikani' => $this->integerRange(
                        StudySettings::DEFAULT_WANIKANI_LANE_WEIGHT,
                        StudySettings::MIN_PRIORITY_LANE_WEIGHT,
                        StudySettings::MAX_LANE_WEIGHT,
                    ),
                ],
            ],
            'cardAuthoring' => [
                'creationKinds' => StudyCardCreationKind::values(),
                'imagePlacements' => StudyCardImagePlacement::values(),
                'previewAudioRoles' => StudyCardAudioRole::values(),
                'defaultAnswerAudioVoiceId' => StudyCardGenerationDefaults::VOICE_ID,
                'defaultFemaleAnswerAudioVoiceId' => StudyCardGenerationDefaults::FEMALE_VOICE_ID,
                'limits' => [
                    'combinedPayloadBytes' => StudyCardDraft::MAX_PAYLOAD_BYTES,
                    'payloadDepth' => StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH,
                    'imagePromptCharacters' => StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH,
                    'imageUploadBytes' => PersistUploadedStudyImageAction::MAX_UPLOAD_BYTES,
                ],
            ],
            'dailyAudio' => [
                'targetDurationMinutes' => $this->integerRange(
                    DailyAudioPracticeGeneration::DEFAULT_TARGET_DURATION_MINUTES,
                    DailyAudioPracticeGeneration::MIN_TARGET_DURATION_MINUTES,
                    DailyAudioPracticeGeneration::MAX_TARGET_DURATION_MINUTES,
                ),
            ],
            'offlineReserve' => [
                'days' => BuildStudyOfflineReserveAction::RESERVE_DAYS,
                'maxScheduledCards' => BuildStudyOfflineReserveAction::MAX_SCHEDULED_CARDS,
            ],
            'imports' => [
                'maxArchiveBytes' => StudyImportJob::MAX_ASYNC_IMPORT_BYTES,
            ],
        ];
    }

    /**
     * @return array{default: int, min: int, max: int}
     */
    private function integerRange(int $default, int $min, int $max): array
    {
        return [
            'default' => $default,
            'min' => $min,
            'max' => $max,
        ];
    }
}
