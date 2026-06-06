<?php

namespace App\Http\Requests\Study\Concerns;

use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
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

    protected function studyCardPayloadAfterValidator(bool $requireText = true): Closure
    {
        return function (Validator $validator) use ($requireText): void {
            // Use raw validator data because after-callbacks still run when field rules fail;
            // validateStudyCardPayloadShape lets prompt/answer rules own missing or non-array errors.
            $data = $validator->getData();
            $this->validateStudyCardPayloadShape(
                fn (string $attribute, string $message) => $validator->errors()->add($attribute, $message),
                $data,
                $requireText,
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
        $values = StudyCardImagePlacement::values();
        $last = array_pop($values);

        if ($last === null) {
            return 'imagePlacement is not supported.';
        }

        if ($values === []) {
            return "imagePlacement must be {$last}.";
        }

        return 'imagePlacement must be '.implode(', ', $values).", or {$last}.";
    }

    protected static function studyCardMediaSourcesMessage(): string
    {
        $values = StudyCardDraft::MEDIA_SOURCES;
        $last = array_pop($values);

        if ($last === null) {
            return 'draft media source is not supported.';
        }

        if ($values === []) {
            return "draft media source must be {$last}.";
        }

        return 'draft media source must be '.implode(', ', $values).", or {$last}.";
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
    private function validateStudyCardPayloadShape(Closure $fail, array $data, bool $requireText): void
    {
        $prompt = $data['prompt'] ?? null;
        $answer = $data['answer'] ?? null;

        // Let the field-level rules report missing or non-array payloads without duplicating errors here.
        if (! is_array($prompt) || ! is_array($answer)) {
            return;
        }

        $serialized = StudyCardPayloadShapeValidator::serializePayloads($prompt, $answer);

        // Serialization runs before depth traversal so invalid or oversized payloads are rejected
        // first; this also bounds how much array width the depth check can walk. Those combined
        // failures use the synthetic payloads key because neither prompt nor answer alone failed.
        if ($serialized === null) {
            $fail('payloads', 'study card payloads contain invalid content.');

            return;
        }

        if (StudyCardPayloadShapeValidator::exceedsMaxBytes($serialized)) {
            // Size is the authoritative combined-payload error when size and depth both fail.
            $fail('payloads', 'study card payloads must be '.StudyCardPayloadShapeValidator::maxPayloadKilobytes().' KB or smaller.');

            return;
        }

        if (StudyCardPayloadShapeValidator::exceedsMaxDepth($prompt)) {
            $fail('prompt', 'prompt must be '.StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (($frontText = StudyCardPayloadText::frontText($prompt)) !== null) {
            $this->frontText = $frontText;
        } elseif ($requireText) {
            $fail('prompt', 'prompt must include a non-empty text field.');
        }

        if (StudyCardPayloadShapeValidator::exceedsMaxDepth($answer)) {
            $fail('answer', 'answer must be '.StudyCardDraft::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (($backText = StudyCardPayloadText::backText($answer)) !== null) {
            $this->backText = $backText;
        } elseif ($requireText) {
            $fail('answer', 'answer must include a non-empty text field.');
        }
    }
}
