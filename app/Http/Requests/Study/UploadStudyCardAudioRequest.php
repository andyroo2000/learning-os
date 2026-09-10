<?php

namespace App\Http\Requests\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\PersistUploadedStudyAudioAction;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UploadStudyCardAudioRequest extends FormRequest
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
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/wav,audio/x-wav,audio/vnd.wave',
                'max:'.PersistUploadedStudyAudioAction::MAX_UPLOAD_KILOBYTES,
            ],
        ];
    }

    public function uploadedAudio(): UploadedFile
    {
        $audio = $this->validated('audio');

        return $audio instanceof UploadedFile
            ? $audio
            : throw new LogicException('Validated audio upload is missing.');
    }

    public function studyCard(): Card
    {
        return $this->studyCard
            ?? throw new LogicException('studyCard() called before authorize() resolved the card.');
    }
}
