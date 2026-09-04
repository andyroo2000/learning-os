<?php

namespace App\Http\Requests\Study\Concerns;

use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Support\StudyCardPayloadSchema;
use App\Domain\Study\Support\StudyCardPayloadShapeValidator;
use App\Domain\Study\Support\StudyCardPayloadText;
use Closure;
use Illuminate\Validation\Validator;
use LogicException;

trait ValidatesStudyCardPayloads
{
    // Nullable so requireText:false callers still fail through frontText()/backText()
    // LogicExceptions instead of uninitialized typed-property errors.
    private ?string $frontText = null;

    private ?string $backText = null;

    /**
     * @return array<string, list<string>>
     */
    protected function studyCardPayloadRules(): array
    {
        return [
            'prompt' => ['required', 'array'],
            'answer' => ['required', 'array'],
        ];
    }

    /**
     * @param  (Closure(array<string, mixed>): bool)|null  $allowPromptWithoutText
     */
    protected function studyCardPayloadAfterValidator(
        bool $requireText = true,
        ?Closure $allowPromptWithoutText = null,
    ): Closure {
        return function (Validator $validator) use ($requireText, $allowPromptWithoutText): void {
            // Use raw validator data because after-callbacks still run when field rules fail;
            // validateStudyCardPayloadShape lets prompt/answer rules own missing or non-array errors.
            $data = $validator->getData();
            $this->validateStudyCardPayloadShape(
                fn (string $attribute, string $message) => $validator->errors()->add($attribute, $message),
                $data,
                requirePromptText: $requireText && ! ($allowPromptWithoutText?->__invoke($data) ?? false),
                requireAnswerText: $requireText,
            );
        };
    }

    /**
     * @return array<string, string>
     */
    protected function studyCardPayloadMessages(): array
    {
        // ConvoLab clients treat missing/non-array prompt or answer as one compatibility contract;
        // the errors object still carries the concrete prompt/answer field keys.
        return [
            'prompt.required' => 'prompt and answer payloads are required.',
            'prompt.array' => 'prompt and answer payloads are required.',
            'answer.required' => 'prompt and answer payloads are required.',
            'answer.array' => 'prompt and answer payloads are required.',
        ];
    }

    protected static function studyCardImagePlacementMessage(): string
    {
        return self::supportedValuesMessage(
            StudyCardImagePlacement::values(),
            'imagePlacement',
        );
    }

    protected static function studyCardMediaSourcesMessage(): string
    {
        return self::supportedValuesMessage(
            StudyCardDraft::MEDIA_SOURCES,
            'draft media source',
        );
    }

    /**
     * @param  list<string>  $values
     */
    private static function supportedValuesMessage(array $values, string $subject): string
    {
        $last = array_pop($values);

        if ($last === null) {
            return "{$subject} is not supported.";
        }

        if ($values === []) {
            return "{$subject} must be {$last}.";
        }

        return "{$subject} must be ".implode(', ', $values).", or {$last}.";
    }

    /**
     * @return array<array-key, mixed>
     */
    public function promptPayload(): array
    {
        $payload = $this->validated('prompt');

        if (! is_array($payload)) {
            throw new LogicException('promptPayload called after validation failed to require an array prompt payload.');
        }

        return $payload;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function answerPayload(): array
    {
        $payload = $this->validated('answer');

        if (! is_array($payload)) {
            throw new LogicException('answerPayload called after validation failed to require an array answer payload.');
        }

        return $payload;
    }

    public function frontText(): string
    {
        return $this->frontText ??= StudyCardPayloadText::frontText($this->promptPayload())
            ?? throw new LogicException('frontText called after validation failed to reject an invalid prompt payload.');
    }

    public function backText(): string
    {
        return $this->backText ??= StudyCardPayloadText::backText($this->answerPayload())
            ?? throw new LogicException('backText called after validation failed to reject an invalid answer payload.');
    }

    /**
     * @param  Closure(string, string): void  $fail
     * @param  array<string, mixed>  $data
     */
    private function validateStudyCardPayloadShape(
        Closure $fail,
        array $data,
        bool $requirePromptText,
        bool $requireAnswerText,
    ): void {
        $prompt = $data['prompt'] ?? null;
        $answer = $data['answer'] ?? null;

        // Let the field-level rules report missing or non-array payloads without duplicating errors here.
        if (! is_array($prompt) || ! is_array($answer)) {
            return;
        }

        if (! self::validateSerializedPayloads($fail, $prompt, $answer)) {
            return;
        }

        $promptIsTooDeep = StudyCardPayloadShapeValidator::exceedsMaxDepth($prompt);
        $answerIsTooDeep = StudyCardPayloadShapeValidator::exceedsMaxDepth($answer);

        self::addPayloadDepthError($fail, 'prompt', $promptIsTooDeep);
        self::addPayloadDepthError($fail, 'answer', $answerIsTooDeep);

        foreach (StudyCardPayloadSchema::validationErrors($prompt, $answer) as $attribute => $message) {
            $fail($attribute, $message);
        }

        $this->capturePayloadText(
            $fail,
            $prompt,
            [
                'is_too_deep' => $promptIsTooDeep,
                'required' => $requirePromptText,
                'attribute' => 'prompt',
                'extract' => StudyCardPayloadText::frontText(...),
                'capture' => function (string $text): void {
                    $this->frontText = $text;
                },
            ],
        );
        $this->capturePayloadText(
            $fail,
            $answer,
            [
                'is_too_deep' => $answerIsTooDeep,
                'required' => $requireAnswerText,
                'attribute' => 'answer',
                'extract' => StudyCardPayloadText::backText(...),
                'capture' => function (string $text): void {
                    $this->backText = $text;
                },
            ],
        );
    }

    /**
     * @param  Closure(string, string): void  $fail
     * @param  array<array-key, mixed>  $prompt
     * @param  array<array-key, mixed>  $answer
     */
    private static function validateSerializedPayloads(Closure $fail, array $prompt, array $answer): bool
    {
        $serialized = StudyCardPayloadShapeValidator::serializePayloads($prompt, $answer);

        // Serialization runs before depth traversal so invalid or oversized payloads are rejected
        // first; this also bounds how much array width the depth check can walk. Those combined
        // failures use the synthetic payloads key because neither prompt nor answer alone failed.
        if ($serialized === null) {
            $fail('payloads', 'study card payloads contain invalid content.');

            return false;
        }

        if (StudyCardPayloadShapeValidator::exceedsMaxBytes($serialized)) {
            // Size is the authoritative combined-payload error when size and depth both fail.
            $fail('payloads', 'study card payloads must be '.StudyCardPayloadShapeValidator::maxPayloadKilobytes().' KB or smaller.');

            return false;
        }

        return true;
    }

    /**
     * @param  Closure(string, string): void  $fail
     */
    private static function addPayloadDepthError(Closure $fail, string $attribute, bool $isTooDeep): void
    {
        if ($isTooDeep) {
            $fail($attribute, "{$attribute} must be ".StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        }
    }

    /**
     * @param  Closure(string, string): void  $fail
     * @param  array<array-key, mixed>  $payload
     * @param  array{
     *     is_too_deep: bool,
     *     required: bool,
     *     attribute: string,
     *     extract: Closure(array<array-key, mixed>): ?string,
     *     capture: Closure(string): void
     * }  $target
     */
    private function capturePayloadText(
        Closure $fail,
        array $payload,
        array $target,
    ): void {
        if (self::payloadCannotContainText($payload, $target['is_too_deep'])) {
            return;
        }

        $text = $target['extract']($payload);

        if ($text !== null) {
            $target['capture']($text);

            return;
        }

        if ($target['required']) {
            $fail($target['attribute'], "{$target['attribute']} must include a non-empty text field.");
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function payloadCannotContainText(array $payload, bool $isTooDeep): bool
    {
        if ($isTooDeep) {
            return true;
        }

        if ($payload === []) {
            return false;
        }

        return array_is_list($payload);
    }
}
