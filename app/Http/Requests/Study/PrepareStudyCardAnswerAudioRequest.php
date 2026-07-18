<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PrepareStudyCardAnswerAudioRequest extends FormRequest
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['*' => ['prohibited']];
    }

    public function studyCard(): Card
    {
        return $this->studyCard
            ?? throw new LogicException('studyCard() called before authorize() resolved the card.');
    }
}
