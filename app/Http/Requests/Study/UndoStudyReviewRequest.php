<?php

namespace App\Http\Requests\Study;

use App\Http\Requests\Concerns\FiltersByStudyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UndoStudyReviewRequest extends FormRequest
{
    use FiltersByStudyScope;

    protected function prepareForValidation(): void
    {
        $this->prepareStudyScopeFiltersForValidation();

        $value = $this->input('timeZone');

        // Leave non-string values untouched so validation reports type errors instead of coercing them.
        if (is_string($value)) {
            $this->merge(['timeZone' => trim($value)]);
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
            'timeZone' => ['sometimes', 'nullable', 'string', 'timezone'],
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

    public function timeZone(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('timeZone', $validated)) {
            return null;
        }

        return $validated['timeZone'] === null ? null : (string) $validated['timeZone'];
    }
}
