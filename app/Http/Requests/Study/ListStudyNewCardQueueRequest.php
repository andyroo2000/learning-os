<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use Illuminate\Foundation\Http\FormRequest;

class ListStudyNewCardQueueRequest extends FormRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['cursor', 'limit', 'q'], blankToNull: ['q']);
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
            'cursor' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.NewCardQueueLimits::PAGE_SIZE_MAX],
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
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
        $validated = $this->validated();

        if (! array_key_exists('q', $validated)) {
            return null;
        }

        return $validated['q'];
    }
}
