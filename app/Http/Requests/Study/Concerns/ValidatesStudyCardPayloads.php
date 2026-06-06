<?php

namespace App\Http\Requests\Study\Concerns;

use App\Domain\Study\Support\StudyCardPayloadText;
use Closure;
use Illuminate\Validation\Validator;
use JsonException;
use LogicException;

trait ValidatesStudyCardPayloads
{
    private const MAX_PAYLOAD_BYTES = 24 * 1024;

    // Maximum nested levels including the prompt/answer payload root itself.
    // Depth 1 is the root payload array; arrays at depth 9+ are rejected.
    private const MAX_TOTAL_PAYLOAD_DEPTH = 8;

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

        // Serialization runs before depth traversal so invalid or oversized payloads are rejected
        // first; this also bounds how much array width the depth check can walk. Those combined
        // failures use the synthetic payloads key because neither prompt nor answer alone failed.
        try {
            $serialized = json_encode(
                ['prompt' => $prompt, 'answer' => $answer],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            $fail('payloads', 'study card payloads contain invalid content.');

            return;
        }

        if (strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            // Size is the authoritative combined-payload error when size and depth both fail.
            $fail('payloads', 'study card payloads must be '.((int) (self::MAX_PAYLOAD_BYTES / 1024)).' KB or smaller.');

            return;
        }

        if (self::exceedsMaxPayloadDepth($prompt)) {
            $fail('prompt', 'prompt must be '.self::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (($frontText = StudyCardPayloadText::frontText($prompt)) !== null) {
            $this->frontText = $frontText;
        } elseif ($requireText) {
            $fail('prompt', 'prompt must include a non-empty text field.');
        }

        if (self::exceedsMaxPayloadDepth($answer)) {
            $fail('answer', 'answer must be '.self::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (($backText = StudyCardPayloadText::backText($answer)) !== null) {
            $this->backText = $backText;
        } elseif ($requireText) {
            $fail('answer', 'answer must include a non-empty text field.');
        }
    }

    private static function exceedsMaxPayloadDepth(mixed $value, int $depth = 1): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > self::MAX_TOTAL_PAYLOAD_DEPTH) {
            return true;
        }

        foreach ($value as $child) {
            if (self::exceedsMaxPayloadDepth($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
