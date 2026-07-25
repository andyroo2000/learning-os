<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Domain\Study\Enums\StudyCardImagePlacement;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadStudyCardImageRequest extends FormRequest
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
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:'.PersistUploadedStudyImageAction::MAX_UPLOAD_KILOBYTES,
            ],
            'imageRole' => [
                'required',
                'string',
                Rule::in(array_values(array_filter(
                    StudyCardImagePlacement::values(),
                    fn (string $value): bool => $value !== StudyCardImagePlacement::None->value,
                ))),
            ],
        ];
    }

    public function image(): UploadedFile
    {
        $image = $this->validated('image');

        return $image instanceof UploadedFile
            ? $image
            : throw new LogicException('Validated image upload is missing.');
    }

    public function imagePlacement(): StudyCardImagePlacement
    {
        return StudyCardImagePlacement::from((string) $this->validated('imageRole'));
    }

    public function studyCard(): Card
    {
        return $this->studyCard
            ?? throw new LogicException('studyCard() called before authorize() resolved the card.');
    }
}
