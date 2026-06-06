<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Data\CreateStudyCardDraftData;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

class StoreStudyCardDraftRequest extends FormRequest
{
    use ValidatesStudyCardPayloads;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['cardType', 'creationKind', 'imagePlacement'] as $key) {
            $value = $this->input($key);

            // Leave non-string values untouched so validation reports type errors instead of coercing them.
            if (is_string($value)) {
                $normalized[$key] = strtolower(trim($value));
            }
        }

        if (array_key_exists('imagePrompt', $this->all())) {
            $value = $this->input('imagePrompt');

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized['imagePrompt'] = $trimmed === '' ? null : $trimmed;
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
            'prompt' => ['present', 'array'],
            'answer' => ['present', 'array'],
            'creationKind' => ['required', 'string', Rule::in(StudyCardCreationKind::values())],
            'cardType' => ['required', 'string', Rule::in(CardType::values())],
            'imagePlacement' => ['sometimes', 'nullable', 'string', Rule::in(StudyCardImagePlacement::values())],
            'imagePrompt' => ['sometimes', 'nullable', 'string', 'max:'.CreateStudyCardDraftData::MAX_IMAGE_PROMPT_LENGTH],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->studyCardPayloadAfterValidator(requireText: false),
            function (Validator $validator): void {
                $data = $validator->getData();

                foreach (['prompt', 'answer'] as $attribute) {
                    $payload = $data[$attribute] ?? null;

                    if (is_array($payload) && $payload !== [] && array_is_list($payload)) {
                        // Match ConvoLab's shared missing/malformed payload error for compatibility.
                        $validator->errors()->add($attribute, 'prompt and answer payloads are required.');
                    }
                }

                $validated = $this->validated();
                $creationKind = $validated['creationKind'] ?? null;
                $cardType = $validated['cardType'] ?? null;

                if (! is_string($creationKind) || ! is_string($cardType)) {
                    return;
                }

                if (StudyCardCreationKind::from($creationKind)->cardType()->value !== $cardType) {
                    $validator->errors()->add('cardType', 'cardType must match creationKind.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->studyCardPayloadMessages(),
            'prompt.present' => 'prompt and answer payloads are required.',
            'answer.present' => 'prompt and answer payloads are required.',
            'creationKind.in' => 'creationKind is not supported.',
            // ConvoLab reports unsupported cardType values and stale-client mismatches the same way.
            'cardType.in' => 'cardType must match creationKind.',
            'imagePlacement.in' => 'imagePlacement must be none, prompt, answer, or both.',
            'imagePrompt.max' => 'imagePrompt must be '.CreateStudyCardDraftData::MAX_IMAGE_PROMPT_LENGTH.' characters or fewer.',
        ];
    }

    public function creationKind(): StudyCardCreationKind
    {
        $value = $this->validated('creationKind');

        if (! is_string($value)) {
            throw new LogicException('creationKind called after validation failed to require a string.');
        }

        return StudyCardCreationKind::from($value);
    }

    public function cardType(): CardType
    {
        $value = $this->validated('cardType');

        if (! is_string($value)) {
            throw new LogicException('cardType called after validation failed to require a string.');
        }

        return CardType::from($value);
    }

    public function imagePlacement(): StudyCardImagePlacement
    {
        $value = $this->validated('imagePlacement');

        if ($value === null) {
            return StudyCardImagePlacement::None;
        }

        if (! is_string($value)) {
            throw new LogicException('imagePlacement called after validation failed to reject a non-string value.');
        }

        return StudyCardImagePlacement::from($value);
    }

    public function imagePrompt(): ?string
    {
        $value = $this->validated('imagePrompt');

        if ($value !== null && ! is_string($value)) {
            throw new LogicException('imagePrompt called after validation failed to reject a non-string value.');
        }

        return $value;
    }
}
