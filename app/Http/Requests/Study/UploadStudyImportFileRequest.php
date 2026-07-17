<?php

namespace App\Http\Requests\Study;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasValidContentLengthHeader()) {
                $validator->errors()->add('file', 'Study import upload content length is invalid.');
            }
        });
    }

    /**
     * @return resource
     */
    public function contents()
    {
        return $this->getContent(asResource: true);
    }

    public function contentType(): ?string
    {
        return $this->headers->get('Content-Type');
    }

    public function contentSizeBytes(): ?int
    {
        $contentLength = $this->normalizedContentLengthHeader();

        if ($contentLength === null) {
            return null;
        }

        return (int) $contentLength;
    }

    private function hasValidContentLengthHeader(): bool
    {
        $contentLength = $this->normalizedContentLengthHeader();

        if ($contentLength === null) {
            return true;
        }

        return $contentLength !== ''
            && ctype_digit($contentLength)
            && ! $this->exceedsNativeIntegerLimit($contentLength);
    }

    private function normalizedContentLengthHeader(): ?string
    {
        $contentLength = $this->headers->get('Content-Length');

        return $contentLength === null ? null : trim($contentLength);
    }

    private function exceedsNativeIntegerLimit(string $value): bool
    {
        $max = (string) PHP_INT_MAX;

        // The caller validates digit-only input before this equal-length string comparison.
        return strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0);
    }
}
