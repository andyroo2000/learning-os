<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Actions\PersistUploadedStudyAudioAction;
use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Domain\Study\Data\CaptureStudyCardData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class CaptureStudyCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'ulid'],
            'japanese' => ['required', 'string', 'max:2000'],
            'english' => ['required', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'audio' => ['required', 'file', 'mimetypes:audio/wav,audio/x-wav,audio/vnd.wave', 'max:'.PersistUploadedStudyAudioAction::MAX_UPLOAD_KILOBYTES],
            'image' => ['sometimes', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.PersistUploadedStudyImageAction::MAX_UPLOAD_KILOBYTES],
        ];
    }

    public function captureData(): CaptureStudyCardData
    {
        return new CaptureStudyCardData($this->validated('id'), $this->validated());
    }

    public function audio(): UploadedFile
    {
        return $this->validated('audio');
    }

    public function image(): ?UploadedFile
    {
        return $this->validated('image');
    }
}
