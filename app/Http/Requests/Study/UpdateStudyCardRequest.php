<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardPayloadText;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStudyCardRequest extends FormRequest
{
    private const MAX_PAYLOAD_BYTES = 24 * 1024;

    // Counts the prompt/answer payload root as level 1; nested children increase from there.
    private const MAX_PAYLOAD_DEPTH = 8;

    private ?Card $studyCard = null;

    public function authorize(): bool
    {
        $user = $this->user();
        $cardId = (string) $this->route('cardId');

        // Authentication middleware returns 401 before the controller can run. For authenticated
        // callers, this lookup hides missing, cross-user, deleted-card, and deleted-deck resources
        // behind the same 404.
        if ($user !== null) {
            $this->studyCard = Card::query()
                ->ownedByActiveDeck((int) $user->id)
                ->where('cards.id', CanonicalUlid::normalize($cardId))
                ->first();

            if ($this->studyCard === null) {
                throw new NotFoundHttpException('Study card not found.');
            }
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
        return $this->validated('prompt');
    }

    /**
     * @return array<string, mixed>
     */
    public function answerPayload(): array
    {
        return $this->validated('answer');
    }

    public function frontText(): string
    {
        return StudyCardPayloadText::frontText($this->promptPayload())
            ?? throw new LogicException('frontText called with invalid prompt payload.');
    }

    public function backText(): string
    {
        return StudyCardPayloadText::backText($this->answerPayload())
            ?? throw new LogicException('backText called with invalid answer payload.');
    }

    /**
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

        $serialized = json_encode(['prompt' => $prompt, 'answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($serialized === false || strlen($serialized) > self::MAX_PAYLOAD_BYTES) {
            $fail('payloads', 'Study card payloads must be '.((int) floor(self::MAX_PAYLOAD_BYTES / 1024)).' KB or smaller.');

            return;
        }

        if (self::exceedsMaxDepth($prompt)) {
            $fail('prompt', 'prompt must be '.self::MAX_PAYLOAD_DEPTH.' levels deep or fewer.');
        }

        if (self::exceedsMaxDepth($answer)) {
            $fail('answer', 'answer must be '.self::MAX_PAYLOAD_DEPTH.' levels deep or fewer.');
        }

        if (StudyCardPayloadText::frontText($prompt) === null) {
            $fail('prompt', 'prompt must include a non-empty text field.');
        }

        if (StudyCardPayloadText::backText($answer) === null) {
            $fail('answer', 'answer must include a non-empty text field.');
        }
    }

    private static function exceedsMaxDepth(mixed $value, int $depth = 1): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > self::MAX_PAYLOAD_DEPTH) {
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
