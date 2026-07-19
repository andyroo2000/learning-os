<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Data\RegenerateStudyCardImageData;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Domain\Study\Models\StudyCardDraft;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RegenerateStudyCardImageRequest extends FormRequest
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
            'imagePrompt' => ['required', 'string', 'max:'.StudyCardDraft::MAX_IMAGE_PROMPT_LENGTH],
            'imageRole' => [
                'required',
                'string',
                Rule::in(array_values(array_filter(
                    StudyCardImagePlacement::values(),
                    fn (string $value): bool => $value !== StudyCardImagePlacement::None->value,
                ))),
            ],
        ];

        foreach (array_diff(array_keys($this->all()), array_keys($rules)) as $unknownKey) {
            $rules[$unknownKey] = ['prohibited'];
        }

        return $rules;
    }

    public function regenerationData(): RegenerateStudyCardImageData
    {
        $validated = $this->validated();

        return RegenerateStudyCardImageData::fromInput(
            imagePrompt: (string) $validated['imagePrompt'],
            imagePlacement: (string) $validated['imageRole'],
        );
    }

    public function studyCard(): Card
    {
        return $this->studyCard
            ?? throw new LogicException('studyCard() called before authorize() resolved the card.');
    }
}
