<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardPayloadText;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use JsonException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStudyCardRequest extends FormRequest
{
    private const MAX_PAYLOAD_BYTES = 24 * 1024;

    // Maximum nested levels including the prompt/answer payload root itself.
    // Depth 1 is the root payload array; arrays at depth 9+ are rejected.
    private const MAX_TOTAL_PAYLOAD_DEPTH = 8;

    private ?Card $studyCard = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $cardId = (string) $this->route('cardId');

        if ($user === null) {
            // Authentication middleware returns 401 first; keep this request invariant explicit
            // if the route middleware is ever changed.
            throw new AuthenticationException;
        }

        $this->studyCard = Card::query()
            ->ownedByActiveDeck((int) $user->id)
            ->where('cards.id', CanonicalUlid::normalize($cardId))
            ->first();

        if ($this->studyCard === null) {
            // Hide missing, cross-user, deleted-card, and deleted-deck resources behind the same 404.
            throw new NotFoundHttpException('Study card not found.');
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'array'],
            'answer' => ['required', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Use raw validator data because after-callbacks still run when field rules fail;
                // validatePayloadShape lets prompt/answer rules own missing or non-array errors.
                $data = $validator->getData();
                $this->validatePayloadShape(
                    fn (string $attribute, string $message) => $validator->errors()->add($attribute, $message),
                    $data,
                );
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
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

    public function studyCard(): Card
    {
        if ($this->studyCard === null) {
            throw new LogicException('studyCard() called before authorize() resolved the card.');
        }

        return $this->studyCard;
    }

    /**
     * @return array<string, mixed>
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
     * @return array<string, mixed>
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
        return StudyCardPayloadText::frontText($this->promptPayload())
            ?? throw new LogicException('frontText called after validation failed to reject an invalid prompt payload.');
    }

    public function backText(): string
    {
        return StudyCardPayloadText::backText($this->answerPayload())
            ?? throw new LogicException('backText called after validation failed to reject an invalid answer payload.');
    }

    /**
     * @param  \Closure(string, string): void  $fail
     * @param  array<string, mixed>  $data
     */
    private function validatePayloadShape(\Closure $fail, array $data): void
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

        if (self::exceedsMaxDepth($prompt)) {
            $fail('prompt', 'prompt must be '.self::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (StudyCardPayloadText::frontText($prompt) === null) {
            $fail('prompt', 'prompt must include a non-empty text field.');
        }

        if (self::exceedsMaxDepth($answer)) {
            $fail('answer', 'answer must be '.self::MAX_TOTAL_PAYLOAD_DEPTH.' levels deep or fewer.');
        } elseif (StudyCardPayloadText::backText($answer) === null) {
            $fail('answer', 'answer must include a non-empty text field.');
        }
    }

    private static function exceedsMaxDepth(mixed $value, int $depth = 1): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > self::MAX_TOTAL_PAYLOAD_DEPTH) {
            return true;
        }

        foreach ($value as $child) {
            if (self::exceedsMaxDepth($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
