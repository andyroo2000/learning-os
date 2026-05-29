<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Values\MimeType;
use App\Domain\Media\Values\PublicUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class StoreMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route requires auth:sanctum; add policy checks here when media ownership grows.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'disk' => $this->trimStringInput('disk'),
            'path' => $this->trimStringInput('path'),
            'mime_type' => $this->trimStringInput('mime_type'),
            'public_url' => $this->trimStringInput('public_url'),
            'original_filename' => $this->trimStringInput('original_filename'),
        ]);
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
            'mime_type' => ['required', 'string', 'max:'.MediaAsset::MAX_MIME_TYPE_LENGTH],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.MediaAsset::MAX_JSON_SAFE_SIZE_BYTES],
            'public_url' => ['nullable', 'string', 'url', 'max:'.MediaAsset::MAX_PUBLIC_URL_LENGTH],
            'checksum_sha256' => ['nullable', 'string', 'size:64', 'regex:/\\A[0-9a-fA-F]+\\z/'],
            'original_filename' => ['nullable', 'string', 'max:'.MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateMimeType($validator);
            $this->validatePublicUrl($validator);
        });
    }

    private function validateMimeType(Validator $validator): void
    {
        if ($validator->errors()->has('mime_type')) {
            return;
        }

        $mimeType = $this->input('mime_type');

        if (! is_string($mimeType)) {
            return;
        }

        $mimeType = MimeType::normalize($mimeType);

        if (! MimeType::hasValidNormalizedShape($mimeType)) {
            $validator->errors()->add('mime_type', 'Media asset MIME type must include a type and subtype.');
        }
    }

    private function validatePublicUrl(Validator $validator): void
    {
        // The base URL rule handles syntax; PublicUrl narrows accepted schemes and hosts.
        if ($validator->errors()->has('public_url')) {
            return;
        }

        $publicUrl = $this->input('public_url');

        if ($publicUrl === null) {
            return;
        }

        try {
            PublicUrl::assertValid($publicUrl, MediaAsset::MAX_PUBLIC_URL_LENGTH);
        } catch (InvalidArgumentException) {
            $validator->errors()->add('public_url', 'The public URL must be a valid public HTTP(S) URL.');
        }
    }

    private function trimStringInput(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) ? trim($value) : $value;
    }
}
