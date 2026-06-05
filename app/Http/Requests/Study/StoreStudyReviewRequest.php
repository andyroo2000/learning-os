<?php

namespace App\Http\Requests\Study;

use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudyReviewRequest extends FormRequest
{
    use NormalizesUlidInput;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'cardId');

        foreach (['grade', 'timeZone'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if (isset($normalized['grade'])) {
            $normalized['grade'] = strtolower($normalized['grade']);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
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
            'cardId' => ['required', 'ulid'],
            'grade' => ['required', Rule::enum(CardReviewRating::class)],
            'durationMs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.ReviewCardData::MAX_DURATION_MS],
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function durationMs(): ?int
    {
        $validated = $this->validated();

        if (! array_key_exists('durationMs', $validated) || $validated['durationMs'] === null) {
            return null;
        }

        return (int) $validated['durationMs'];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'];
    }
}
