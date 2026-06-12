<?php

namespace App\Http\Requests\Concerns;

use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Validation\Validator;

trait FiltersByStudyScope
{
    use NormalizesStringInputs;

    protected function prepareStudyScopeFiltersForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['courseId', 'deckId', 'deck_id']);
        // HTTP callers normalize before validation; actions normalize again because they also support direct callers.
        $this->mergeStringInputsUsing(
            ['courseId', 'deckId', 'deck_id'],
            fn (string $value): string => CanonicalUlid::normalize($value),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    protected function studyScopeRules(): array
    {
        return [
            'courseId' => ['sometimes', 'filled', 'ulid'],
            'deckId' => ['sometimes', 'filled', 'ulid'],
            'deck_id' => ['sometimes', 'filled', 'ulid'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    protected function studyScopeAfterValidationCallbacks(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['deckId', 'deck_id'])) {
                    return;
                }

                if ($validator->errors()->any()) {
                    return;
                }

                $validated = $this->validated();
                $deckId = $validated['deckId'] ?? null;
                $legacyDeckId = $validated['deck_id'] ?? null;

                if (! is_string($deckId) || ! is_string($legacyDeckId)) {
                    return;
                }

                if ($deckId !== $legacyDeckId) {
                    $validator->errors()->add('deckId', 'The deckId and deck_id filters must match when both are provided.');
                }
            },
        ];
    }

    public function courseId(): ?string
    {
        // `courseId` + `deckId` intentionally intersect in the query layer; mismatches return an empty 200.
        return $this->nullableString('courseId');
    }

    public function deckId(): ?string
    {
        return $this->nullableString('deckId')
            ?? $this->nullableString('deck_id');
    }
}
