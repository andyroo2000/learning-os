<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Data\RegenerateStudyCardAnswerAudioData;
use App\Domain\Study\Services\FishAudioSpeechGenerator;
use App\Domain\Study\Support\StudyCardGenerationDefaults;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RegenerateStudyCardAnswerAudioRequest extends FormRequest
{
    private ?Card $studyCard = null;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            throw new AuthenticationException;
        }

        $this->studyCard = Card::query()
            ->ownedByActiveDeck(AuthenticatedUser::id($this))
            ->whereClientIdentifier((string) $this->route('cardId'))
            ->first();

        if ($this->studyCard === null) {
            throw new NotFoundHttpException('Study card not found.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'answerAudioVoiceId' => [
                'sometimes',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || StudyCardGenerationDefaults::normalizeVoiceId($value) === null) {
                        $fail("The {$attribute} field format is invalid.");
                    }
                },
            ],
            'answerAudioTextOverride' => ['sometimes', 'nullable', 'string', 'max:'.FishAudioSpeechGenerator::MAX_TEXT_LENGTH],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $unknownKey) {
            $rules[$unknownKey] = ['prohibited'];
        }

        return $rules;
    }

    public function regenerationData(): RegenerateStudyCardAnswerAudioData
    {
        $validated = $this->validated();

        return RegenerateStudyCardAnswerAudioData::fromInput(
            hasVoiceId: array_key_exists('answerAudioVoiceId', $validated),
            voiceId: $validated['answerAudioVoiceId'] ?? null,
            hasTextOverride: array_key_exists('answerAudioTextOverride', $validated),
            textOverride: $validated['answerAudioTextOverride'] ?? null,
        );
    }

    public function studyCard(): Card
    {
        return $this->studyCard
            ?? throw new LogicException('studyCard() called before authorize() resolved the card.');
    }
}
