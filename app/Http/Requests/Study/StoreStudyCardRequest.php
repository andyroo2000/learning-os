<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

class StoreStudyCardRequest extends FormRequest
{
    use ValidatesStudyCardPayloads;

    private const CREATION_KIND_TO_CARD_TYPE = [
        'text-recognition' => CardType::Recognition,
        'audio-recognition' => CardType::Recognition,
        'production-text' => CardType::Production,
        'production-image' => CardType::Production,
        'cloze' => CardType::Cloze,
    ];

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['id'] as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = CanonicalUlid::normalize($value);
            }
        }

        foreach (['cardType', 'creationKind'] as $key) {
            $value = $this->input($key);

            // Leave non-string values untouched so validation reports type errors instead of coercing them.
            if (is_string($value)) {
                $normalized[$key] = strtolower(trim($value));
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function authorize(): bool
    {
        if ($this->user() === null) {
            // Authentication middleware returns 401 first; keep this request invariant explicit
            // if the route middleware is ever changed.
            throw new AuthenticationException;
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->studyCardPayloadRules(),
            'id' => ['nullable', 'ulid'],
            'creationKind' => ['sometimes', 'required', 'string', Rule::in(array_keys(self::CREATION_KIND_TO_CARD_TYPE))],
            // Legacy callers send cardType; when creationKind is present, it owns the persisted card type.
            'cardType' => ['exclude_with:creationKind', 'required_without:creationKind', 'string', Rule::in(CardType::values())],
        ];
    }

    public function after(): array
    {
        return [$this->studyCardPayloadAfterValidator()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->studyCardPayloadMessages(),
            'cardType.required_without' => self::cardTypeMessage(),
            'cardType.in' => self::cardTypeMessage(),
            'creationKind.in' => 'creationKind is not supported.',
        ];
    }

    public function cardType(): CardType
    {
        $validated = $this->validated();

        if (array_key_exists('creationKind', $validated)) {
            $creationKind = $validated['creationKind'];

            if (! is_string($creationKind) || ! array_key_exists($creationKind, self::CREATION_KIND_TO_CARD_TYPE)) {
                throw new LogicException('cardType called after validation failed to reject an invalid creationKind.');
            }

            return self::CREATION_KIND_TO_CARD_TYPE[$creationKind];
        }

        $cardType = $validated['cardType'] ?? null;

        if (! is_string($cardType)) {
            throw new LogicException('cardType called after validation failed to require cardType.');
        }

        return CardType::from($cardType);
    }

    public function id(): ?string
    {
        $id = $this->validated('id');

        if ($id !== null && ! is_string($id)) {
            throw new LogicException('id called after validation failed to reject a non-string card ID.');
        }

        return $id;
    }

    private static function cardTypeMessage(): string
    {
        $values = CardType::values();
        $last = array_pop($values);

        if ($last === null) {
            return 'cardType is not supported.';
        }

        if ($values === []) {
            return "cardType must be {$last}.";
        }

        return 'cardType must be '.implode(', ', $values).", or {$last}.";
    }
}
