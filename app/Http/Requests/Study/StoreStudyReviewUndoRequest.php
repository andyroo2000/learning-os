<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\FiltersByStudyScope;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreStudyReviewUndoRequest extends FormRequest
{
    use FiltersByStudyScope;
    use NormalizesUlidInput;

    protected function prepareForValidation(): void
    {
        $this->prepareStudyScopeFiltersForValidation();

        $normalized = [];

        $this->mergeNormalizedUlidInput($normalized, 'reviewLogId');

        $timeZone = $this->input('timeZone');

        // Leave non-string values untouched so validation reports type errors instead of coercing them.
        if (is_string($timeZone)) {
            $normalized['timeZone'] = trim($timeZone);
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
            'reviewLogId' => ['required', 'ulid'],
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
            // currentOverview is accepted for ConvoLab request compatibility; controllers recompute overview.
            'currentOverview' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return $this->studyScopeAfterValidationCallbacks();
    }

    public function reviewLogId(): string
    {
        return (string) $this->validated()['reviewLogId'];
    }

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }
}
