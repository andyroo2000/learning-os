<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateStudySettingsRequest extends FormRequest
{
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
            'min:0',
            'max:'.StudySettings::MAX_NEW_CARDS_PER_DAY,
        ];
        $lessonBatchRules = [
            'integer',
            'min:'.StudySettings::MIN_LESSON_BATCH_SIZE,
            'max:'.StudySettings::MAX_LESSON_BATCH_SIZE,
        ];

        return [
            'newCardsPerDay' => ['sometimes', ...$newCardsRules],
            'new_cards_per_day' => ['sometimes', ...$newCardsRules],
            'lessonBatchSize' => ['sometimes', ...$lessonBatchRules],
            'lesson_batch_size' => ['sometimes', ...$lessonBatchRules],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->any()) {
                    return;
                }

                $validated = $validator->safe()->all();

                if (! collect([
                    'newCardsPerDay',
                    'new_cards_per_day',
                    'lessonBatchSize',
                    'lesson_batch_size',
                ])->contains(fn (string $key): bool => array_key_exists($key, $validated))) {
                    $validator->errors()->add('settings', 'At least one study setting must be provided.');

                    return;
                }

                if (
                    array_key_exists('newCardsPerDay', $validated)
                    && array_key_exists('new_cards_per_day', $validated)
                    && (int) $validated['newCardsPerDay'] !== (int) $validated['new_cards_per_day']
                ) {
                    $validator->errors()->add(
                        'newCardsPerDay',
                        'The newCardsPerDay and new_cards_per_day values must match when both are provided.',
                    );
                }

                if (
                    array_key_exists('lessonBatchSize', $validated)
                    && array_key_exists('lesson_batch_size', $validated)
                    && (int) $validated['lessonBatchSize'] !== (int) $validated['lesson_batch_size']
                ) {
                    $validator->errors()->add(
                        'lessonBatchSize',
                        'The lessonBatchSize and lesson_batch_size values must match when both are provided.',
                    );
                }
            },
        ];
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
}
