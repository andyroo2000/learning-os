<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Http\Requests\Concerns\FiltersByStudyScope;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStudyReviewRequest extends FormRequest
{
    use FiltersByStudyScope;
    use NormalizesUlidInput;

    protected function prepareForValidation(): void
    {
        $this->prepareStudyScopeFiltersForValidation();

        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'cardId');

        foreach (['grade', 'timeZone'] as $key) {
            $value = $this->input($key);

            // Leave non-string values untouched so validation reports type errors instead of coercing them.
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
            ...$this->studyScopeRules(),
            'cardId' => ['required', 'string', 'regex:/^'.Card::CLIENT_ID_ROUTE_PATTERN.'$/'],
            'grade' => ['required', Rule::enum(CardReviewRating::class)],
            'durationMs' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.ReviewCardData::MAX_DURATION_MS],
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cardId.required' => 'cardId is required.',
            'cardId.string' => 'cardId must be a valid Study card id.',
            'cardId.regex' => 'cardId must be a valid Study card id.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return $this->studyScopeAfterValidationCallbacks();
    }

    /**
     * @param  array<string, mixed>|null  $validated
     */
    public function durationMs(?array $validated = null): ?int
    {
        $validated ??= $this->validated();

        if (! array_key_exists('durationMs', $validated) || $validated['durationMs'] === null) {
            return null;
        }

        // Laravel integer validation accepts numeric strings; cast before crossing the action boundary.
        return (int) $validated['durationMs'];
    }

    /**
     * @param  array<string, mixed>|null  $validated
     */
    public function timeZone(?array $validated = null): ?string
    {
        $validated ??= $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }
}
