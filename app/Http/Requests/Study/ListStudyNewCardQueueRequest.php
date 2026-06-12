<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Support\NewCardQueueLimits;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class ListStudyNewCardQueueRequest extends FormRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['cursor', 'limit', 'q', 'courseId', 'deckId'], blankToNull: ['q']);
        $this->mergeStringInputsUsing(
            ['courseId', 'deckId'],
            fn (string $value): string => CanonicalUlid::normalize($value),
        );
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
            'courseId' => ['sometimes', 'filled', 'ulid'],
            'deckId' => ['sometimes', 'filled', 'ulid'],
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
        return $this->nullableString('q');
    }

    public function courseId(): ?string
    {
        return $this->nullableString('courseId');
    }

    public function deckId(): ?string
    {
        return $this->nullableString('deckId');
    }
}
