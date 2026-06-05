<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;

class UploadStudyImportFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    public function contents(): string
    {
        return $this->getContent();
    }

    public function contentType(): ?string
    {
        return $this->headers->get('Content-Type');
    }

    public function contentSizeBytes(): ?int
    {
        $contentLength = $this->headers->get('Content-Length');

        return $contentLength === null ? null : (int) $contentLength;
    }
}
