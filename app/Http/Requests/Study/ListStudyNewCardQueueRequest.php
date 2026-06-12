<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Http\Requests\Concerns\FiltersByStudyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ListStudyNewCardQueueRequest extends FormRequest
{
    use FiltersByStudyScope;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['cursor', 'limit', 'q'], blankToNull: ['q']);
        $this->prepareStudyScopeFiltersForValidation();
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'filled', 'integer', 'min:0'],
            'limit' => ['sometimes', 'filled', 'integer', 'min:1', 'max:'.NewCardQueueLimits::PAGE_SIZE_MAX],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            ...$this->studyScopeRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return $this->studyScopeAfterValidationCallbacks();
    }

    public function cursor(): int
    {
        return (int) ($this->validated()['cursor'] ?? 0);
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? NewCardQueueLimits::PAGE_SIZE_DEFAULT);
    }

    public function q(): ?string
    {
        return $this->nullableString('q');
    }
}
