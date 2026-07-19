<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class StoreDailyAudioPracticeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $timeZone = $this->input('timeZone');

        if (is_string($timeZone)) {
            $this->merge(['timeZone' => trim($timeZone)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'timeZone' => ['sometimes', 'nullable', 'string', 'max:64', 'timezone'],
            'targetDurationMinutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:'.DailyAudioPracticeGeneration::MIN_TARGET_DURATION_MINUTES,
                'max:'.DailyAudioPracticeGeneration::MAX_TARGET_DURATION_MINUTES,
            ],
        ];
    }

    public function practiceDate(): string
    {
        $timeZone = $this->validated('timeZone');
        if ($timeZone !== null && ! is_string($timeZone)) {
            throw new LogicException('timeZone called after validation failed to reject a non-string value.');
        }

        return CarbonImmutable::now($timeZone ?? 'UTC')->toDateString();
    }

    public function targetDurationMinutes(): int
    {
        $value = $this->validated('targetDurationMinutes');

        return is_int($value)
            ? $value
            : DailyAudioPracticeGeneration::DEFAULT_TARGET_DURATION_MINUTES;
    }
}
