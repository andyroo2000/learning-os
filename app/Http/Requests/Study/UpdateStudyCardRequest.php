<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Http\Requests\Study\Concerns\ValidatesStudyCardPayloads;
use App\Http\Requests\Study\Concerns\ValidatesVocabVariantMetadata;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateStudyCardRequest extends FormRequest
{
    use ValidatesStudyCardPayloads;
    use ValidatesVocabVariantMetadata;

    private ?Card $studyCard = null;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        $this->normalizeVariantMetadataForValidation($normalized);

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

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
            ->ownedByActiveDeck(AuthenticatedUser::id($this))
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
            ...$this->studyCardPayloadRules(),
            ...$this->variantMetadataRules(),
        ];
    }

    public function after(): array
    {
        return [$this->studyCardPayloadAfterValidator()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->studyCardPayloadMessages(),
            ...$this->variantMetadataMessages(),
        ];
    }

    public function studyCard(): Card
    {
        if ($this->studyCard === null) {
            throw new LogicException('studyCard() called before authorize() resolved the card.');
        }

        return $this->studyCard;
    }
}
