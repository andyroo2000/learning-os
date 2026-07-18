<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudyCardDraft;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GenerateStudyCardDraftPreviewRequest extends FormRequest
{
    private ?StudyCardDraft $studyCardDraft = null;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            throw new AuthenticationException;
        }

        $this->studyCardDraft = StudyCardDraft::query()
            ->where('user_id', AuthenticatedUser::id($this))
            ->whereKey(CanonicalUlid::normalize((string) $this->route('draftId')))
            ->first();

        if ($this->studyCardDraft === null) {
            throw new NotFoundHttpException('Study card draft not found.');
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            '*' => ['prohibited'],
        ];
    }

    public function studyCardDraft(): StudyCardDraft
    {
        if ($this->studyCardDraft === null) {
            throw new LogicException('studyCardDraft() called before authorize() resolved the draft.');
        }

        return $this->studyCardDraft;
    }
}
