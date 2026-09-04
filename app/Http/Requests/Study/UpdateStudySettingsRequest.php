<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStudySettingsRequest extends FormRequest
{
    /** @var list<string> */
    private const SETTING_KEYS = [
        'newCardsPerDay',
        'new_cards_per_day',
        'lessonBatchSize',
        'lesson_batch_size',
        'reviewTimeBudgetMinutes',
        'review_time_budget_minutes',
        'newCardLaneWeights',
        'new_card_lane_weights',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $newCardsRules = [
            'integer',
            'min:'.StudySettings::MIN_NEW_CARDS_PER_DAY,
            'max:'.StudySettings::MAX_NEW_CARDS_PER_DAY,
        ];
        $lessonBatchRules = [
            'integer',
            'min:'.StudySettings::MIN_LESSON_BATCH_SIZE,
            'max:'.StudySettings::MAX_LESSON_BATCH_SIZE,
        ];
        $reviewTimeBudgetRules = [
            'integer',
            'min:'.StudySettings::MIN_REVIEW_TIME_BUDGET_MINUTES,
            'max:'.StudySettings::MAX_REVIEW_TIME_BUDGET_MINUTES,
        ];
        $priorityLaneWeightRules = [
            'integer',
            'min:'.StudySettings::MIN_PRIORITY_LANE_WEIGHT,
            'max:'.StudySettings::MAX_LANE_WEIGHT,
        ];
        $standardLaneWeightRules = [
            'integer',
            'min:'.StudySettings::MIN_STANDARD_LANE_WEIGHT,
            'max:'.StudySettings::MAX_LANE_WEIGHT,
        ];

        return [
            'newCardsPerDay' => ['sometimes', ...$newCardsRules],
            'new_cards_per_day' => ['sometimes', ...$newCardsRules],
            'lessonBatchSize' => ['sometimes', ...$lessonBatchRules],
            'lesson_batch_size' => ['sometimes', ...$lessonBatchRules],
            'reviewTimeBudgetMinutes' => ['sometimes', ...$reviewTimeBudgetRules],
            'review_time_budget_minutes' => ['sometimes', ...$reviewTimeBudgetRules],
            'newCardLaneWeights' => ['sometimes', 'array:standard,lessonFollowup,wanikani'],
            'newCardLaneWeights.standard' => ['required_with:newCardLaneWeights', ...$standardLaneWeightRules],
            'newCardLaneWeights.lessonFollowup' => ['required_with:newCardLaneWeights', ...$priorityLaneWeightRules],
            'newCardLaneWeights.wanikani' => ['required_with:newCardLaneWeights', ...$priorityLaneWeightRules],
            'new_card_lane_weights' => ['sometimes', 'array:standard,lesson_followup,wanikani'],
            'new_card_lane_weights.standard' => ['required_with:new_card_lane_weights', ...$standardLaneWeightRules],
            'new_card_lane_weights.lesson_followup' => ['required_with:new_card_lane_weights', ...$priorityLaneWeightRules],
            'new_card_lane_weights.wanikani' => ['required_with:new_card_lane_weights', ...$priorityLaneWeightRules],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSettingAliases($validator);
            },
        ];
    }

    private function validateSettingAliases(Validator $validator): void
    {
        if ($validator->errors()->any()) {
            return;
        }

        $validated = $validator->safe()->all();

        if (! self::hasSetting($validated)) {
            $validator->errors()->add('settings', 'At least one study setting must be provided.');

            return;
        }

        self::addScalarAliasMismatch($validator, $validated, [
            'camel' => 'newCardsPerDay',
            'snake' => 'new_cards_per_day',
            'message' => 'The newCardsPerDay and new_cards_per_day values must match when both are provided.',
        ]);
        $this->addLaneWeightAliasMismatch($validator, $validated);
        self::addScalarAliasMismatch($validator, $validated, [
            'camel' => 'reviewTimeBudgetMinutes',
            'snake' => 'review_time_budget_minutes',
            'message' => 'The reviewTimeBudgetMinutes and review_time_budget_minutes values must match when both are provided.',
        ]);
        self::addScalarAliasMismatch($validator, $validated, [
            'camel' => 'lessonBatchSize',
            'snake' => 'lesson_batch_size',
            'message' => 'The lessonBatchSize and lesson_batch_size values must match when both are provided.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function hasSetting(array $validated): bool
    {
        foreach (self::SETTING_KEYS as $key) {
            if (array_key_exists($key, $validated)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array{camel: string, snake: string, message: string}  $aliases
     */
    private static function addScalarAliasMismatch(
        Validator $validator,
        array $validated,
        array $aliases,
    ): void {
        if (! array_key_exists($aliases['camel'], $validated)) {
            return;
        }

        if (! array_key_exists($aliases['snake'], $validated)) {
            return;
        }

        if ((int) $validated[$aliases['camel']] === (int) $validated[$aliases['snake']]) {
            return;
        }

        $validator->errors()->add($aliases['camel'], $aliases['message']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function addLaneWeightAliasMismatch(Validator $validator, array $validated): void
    {
        if (! array_key_exists('newCardLaneWeights', $validated)) {
            return;
        }

        if (! array_key_exists('new_card_lane_weights', $validated)) {
            return;
        }

        if ($this->camelLaneWeights($validated['newCardLaneWeights'])
            === $this->snakeLaneWeights($validated['new_card_lane_weights'])) {
            return;
        }

        $validator->errors()->add(
            'newCardLaneWeights',
            'The newCardLaneWeights and new_card_lane_weights values must match when both are provided.',
        );
    }

    public function newCardsPerDay(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('newCardsPerDay', $validated) && ! array_key_exists('new_cards_per_day', $validated)) {
            return null;
        }

        return (int) ($validated['newCardsPerDay'] ?? $validated['new_cards_per_day']);
    }

    public function lessonBatchSize(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('lessonBatchSize', $validated) && ! array_key_exists('lesson_batch_size', $validated)) {
            return null;
        }

        return (int) ($validated['lessonBatchSize'] ?? $validated['lesson_batch_size']);
    }

    public function reviewTimeBudgetMinutes(): ?int
    {
        $validated = $this->validated();

        if (
            ! array_key_exists('reviewTimeBudgetMinutes', $validated)
            && ! array_key_exists('review_time_budget_minutes', $validated)
        ) {
            return null;
        }

        return (int) ($validated['reviewTimeBudgetMinutes'] ?? $validated['review_time_budget_minutes']);
    }

    public function standardLaneWeight(): ?int
    {
        return $this->laneWeights()['standard'] ?? null;
    }

    public function lessonFollowupLaneWeight(): ?int
    {
        return $this->laneWeights()['lesson_followup'] ?? null;
    }

    public function wanikaniLaneWeight(): ?int
    {
        return $this->laneWeights()['wanikani'] ?? null;
    }

    /**
     * @return array{standard: int, lesson_followup: int, wanikani: int}|null
     */
    private function laneWeights(): ?array
    {
        $validated = $this->validated();

        if (array_key_exists('newCardLaneWeights', $validated)) {
            return $this->camelLaneWeights($validated['newCardLaneWeights']);
        }

        if (array_key_exists('new_card_lane_weights', $validated)) {
            return $this->snakeLaneWeights($validated['new_card_lane_weights']);
        }

        return null;
    }

    /**
     * @param  array{standard: int|string, lessonFollowup: int|string, wanikani: int|string}  $weights
     * @return array{standard: int, lesson_followup: int, wanikani: int}
     */
    private function camelLaneWeights(array $weights): array
    {
        return [
            'standard' => (int) $weights['standard'],
            'lesson_followup' => (int) $weights['lessonFollowup'],
            'wanikani' => (int) $weights['wanikani'],
        ];
    }

    /**
     * @param  array{standard: int|string, lesson_followup: int|string, wanikani: int|string}  $weights
     * @return array{standard: int, lesson_followup: int, wanikani: int}
     */
    private function snakeLaneWeights(array $weights): array
    {
        return [
            'standard' => (int) $weights['standard'],
            'lesson_followup' => (int) $weights['lesson_followup'],
            'wanikani' => (int) $weights['wanikani'],
        ];
    }
}
