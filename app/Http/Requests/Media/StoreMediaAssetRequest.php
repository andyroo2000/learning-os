<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\MimeType;
use App\Domain\Media\Values\PublicUrl;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class StoreMediaAssetRequest extends FormRequest
{
    use NormalizesUlidInput;

    public function authorize(): bool
    {
        // The route requires auth:sanctum; add policy checks here when media ownership grows.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [
            'disk' => $this->trimStringInput('disk'),
            'path' => $this->trimStringInput('path'),
            'mime_type' => $this->trimStringInput('mime_type'),
            'public_url' => $this->trimStringInput('public_url'),
            'original_filename' => $this->trimStringInput('original_filename'),
        ];

        $this->mergeNormalizedUlidInput($input, 'id');

        $this->merge($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'ulid'],
            'disk' => ['required', 'string', 'max:'.MediaAsset::MAX_DISK_LENGTH, Rule::in(MediaAsset::ALLOWED_DISKS)],
            'path' => [
                'required',
                'string',
                'max:'.MediaAsset::MAX_PATH_LENGTH,
                'not_regex:'.MediaAsset::PATH_ABSOLUTE_PATTERN,
                'not_regex:'.MediaAsset::PATH_TRAVERSAL_PATTERN,
            ],
            'mime_type' => [
                'bail',
                'required',
                'string',
                'max:'.MediaAsset::MAX_MIME_TYPE_LENGTH,
                $this->validMimeTypeShapeRule(),
            ],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.MediaAsset::MAX_JSON_SAFE_SIZE_BYTES],
            'public_url' => [
                'bail',
                'nullable',
                'string',
                'url',
                'max:'.MediaAsset::MAX_PUBLIC_URL_LENGTH,
                $this->publicHttpUrlRule(),
            ],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/\\A[0-9a-fA-F]+\\z/'],
            'original_filename' => ['nullable', 'string', 'max:'.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH],
        ];
    }

    private function validMimeTypeShapeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            if (! MimeType::hasValidNormalizedShape(MimeType::normalize($value))) {
                $fail('Media asset MIME type must include a type and subtype.');
            }
        };
    }

    private function publicHttpUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || ! is_string($value)) {
                return;
            }

            try {
                PublicUrl::assertValid($value, MediaAsset::MAX_PUBLIC_URL_LENGTH);
            } catch (InvalidArgumentException) {
                $fail('The public URL must be a valid public HTTP(S) URL.');
            }
        };
    }

    private function trimStringInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
