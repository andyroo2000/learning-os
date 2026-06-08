<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Enums\StudyCardCreationKind;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Vocabulary\Enums\VocabVariantKind;
use App\Domain\Vocabulary\Enums\VocabVariantStatus;
use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

class StoreStudyCardDraftRequest extends FormRequest
{
    use ValidatesStudyCardPayloads;

    private const PAYLOAD_REQUIRED_MESSAGE = 'prompt and answer payloads are required.';

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

        foreach (['variantKind', 'variantStatus'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : strtolower($trimmed);
            }
        }

        foreach (['imagePrompt', 'variantGroupId', 'variantSentenceId', 'variantStage', 'variantUnlockedAt'] as $key) {
            if (! array_key_exists($key, $this->all())) {
                continue;
            }

            $value = $this->input($key);

            if (is_string($value)) {
                $trimmed = trim($value);
                $normalized[$key] = $trimmed === '' ? null : $trimmed;
            }
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
            'prompt' => ['present', 'array'],
            'answer' => ['present', 'array'],
            'creationKind' => ['required', 'string', Rule::in(StudyCardCreationKind::values())],
            'cardType' => ['required', 'string', Rule::in(CardType::values())],
            'imagePlacement' => ['sometimes', 'nullable', 'string', Rule::in(StudyCardImagePlacement::values())],
            'imagePrompt' => ['sometimes', 'nullable', 'string', 'max:'.StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH],
            'variantGroupId' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variantSentenceId' => ['sometimes', 'nullable', 'string', 'max:'.VocabVariantMetadataInput::MAX_ID_LENGTH],
            'variantKind' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantKind::values())],
            'variantStage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.VocabVariantMetadataInput::MAX_STAGE],
            'variantStatus' => ['sometimes', 'nullable', 'string', Rule::in(VocabVariantStatus::values())],
            'variantUnlockedAt' => [
                'sometimes',
                'nullable',
                'string',
                'date_format:Y-m-d\TH:i:s.uP,Y-m-d\TH:i:sP,Y-m-d\TH:i:s.u\Z,Y-m-d\TH:i:s\Z,Y-m-d\TH:i:s',
            ],
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
                        $validator->errors()->add($attribute, self::PAYLOAD_REQUIRED_MESSAGE);
                    }
                }

                $creationKind = $data['creationKind'] ?? null;
                $cardType = $data['cardType'] ?? null;

                if (! is_string($creationKind) || ! is_string($cardType)) {
                    return;
                }

                $creationKind = StudyCardCreationKind::tryFrom($creationKind);
                $cardType = CardType::tryFrom($cardType);

                if ($creationKind === null || $cardType === null) {
                    return;
                }

                if ($creationKind->cardType() !== $cardType) {
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
            'prompt.present' => self::PAYLOAD_REQUIRED_MESSAGE,
            'prompt.array' => self::PAYLOAD_REQUIRED_MESSAGE,
            'answer.present' => self::PAYLOAD_REQUIRED_MESSAGE,
            'answer.array' => self::PAYLOAD_REQUIRED_MESSAGE,
            'creationKind.in' => 'creationKind is not supported.',
            // ConvoLab reports unsupported cardType values and stale-client mismatches the same way.
            'cardType.in' => 'cardType must match creationKind.',
            'imagePlacement.in' => self::studyCardImagePlacementMessage(),
            'imagePrompt.max' => 'imagePrompt must be '.StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH.' characters or fewer.',
            'variantGroupId.string' => 'variantGroupId must be a string.',
            'variantGroupId.max' => 'variantGroupId must be '.VocabVariantMetadataInput::MAX_ID_LENGTH.' characters or fewer.',
            'variantSentenceId.string' => 'variantSentenceId must be a string.',
            'variantSentenceId.max' => 'variantSentenceId must be '.VocabVariantMetadataInput::MAX_ID_LENGTH.' characters or fewer.',
            'variantKind.string' => 'variantKind must be a string.',
            'variantKind.in' => 'variantKind is not supported.',
            'variantStage.integer' => 'variantStage must be an integer.',
            'variantStage.min' => 'variantStage must be between 1 and '.VocabVariantMetadataInput::MAX_STAGE.'.',
            'variantStage.max' => 'variantStage must be between 1 and '.VocabVariantMetadataInput::MAX_STAGE.'.',
            'variantStatus.string' => 'variantStatus must be a string.',
            'variantStatus.in' => 'variantStatus is not supported.',
            'variantUnlockedAt.string' => 'variantUnlockedAt must be a string.',
            'variantUnlockedAt.date_format' => 'variantUnlockedAt must be a valid timestamp.',
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

    public function imagePlacement(): ?StudyCardImagePlacement
    {
        $validated = $this->validated();
        $value = $validated['imagePlacement'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('imagePlacement called after validation failed to reject a non-string value.');
        }

        return StudyCardImagePlacement::from($value);
    }

    public function imagePrompt(): ?string
    {
        return $this->nullableString('imagePrompt');
    }

    public function variantGroupId(): ?string
    {
        return $this->nullableString('variantGroupId');
    }

    public function variantSentenceId(): ?string
    {
        return $this->nullableString('variantSentenceId');
    }

    public function variantKind(): ?VocabVariantKind
    {
        $value = $this->nullableString('variantKind');

        return $value === null ? null : VocabVariantKind::from($value);
    }

    public function variantStage(): ?int
    {
        $value = $this->validated('variantStage');

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new LogicException('variantStage called after validation failed to reject a non-integer value.');
        }

        return (int) $value;
    }

    public function variantStatus(): ?VocabVariantStatus
    {
        $value = $this->nullableString('variantStatus');

        return $value === null ? null : VocabVariantStatus::from($value);
    }

    public function variantUnlockedAt(): ?DateTimeInterface
    {
        $value = $this->validated('variantUnlockedAt');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new LogicException('variantUnlockedAt called after validation failed to reject a non-string value.');
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        if ($value !== null && ! is_string($value)) {
            throw new LogicException("{$key} called after validation failed to reject a non-string value.");
        }

        return $value;
    }
}
