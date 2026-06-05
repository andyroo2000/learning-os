<?php

namespace App\Http\Requests\Study;

use App\Domain\Study\Models\StudyImportJob;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudyImportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $filename = $this->input('filename');
        $contentType = $this->input('content_type');

        $this->merge([
            'filename' => is_string($filename) ? trim($filename) : $filename,
            'content_type' => is_string($contentType) ? strtolower(trim($contentType)) : $contentType,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'filename' => [
                'bail',
                'required',
                'string',
                'max:'.StudyImportJob::MAX_SOURCE_FILENAME_LENGTH,
                'not_regex:~[\\\\/]~',
                $this->colpkgFilenameRule(),
            ],
            'content_type' => [
                'nullable',
                'string',
                'max:'.StudyImportJob::MAX_SOURCE_CONTENT_TYPE_LENGTH,
                Rule::in(StudyImportJob::ALLOWED_CONTENT_TYPES),
            ],
        ];
    }

    public function filename(): string
    {
        return $this->validated()['filename'];
    }

    public function contentType(): ?string
    {
        $validated = $this->validated();

        if (! array_key_exists('content_type', $validated)) {
            return null;
        }

        return $validated['content_type'];
    }

    private function colpkgFilenameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (! str_ends_with(strtolower($value), '.colpkg')) {
                $fail('Only .colpkg Anki collection backups are accepted.');
            }
        };
    }
}
