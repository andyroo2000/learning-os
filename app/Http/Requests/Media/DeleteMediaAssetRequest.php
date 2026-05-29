<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class DeleteMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the delete action's scoped query so repeat deletes,
        // missing assets, and cross-user IDs all return the same idempotent 204 outcome.
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function userId(): int
    {
        return $this->user()->id;
    }

    public function mediaAssetId(): string
    {
        $mediaAssetId = $this->route('mediaAsset');

        if (! is_string($mediaAssetId) || $mediaAssetId === '') {
            abort(404);
        }

        return $mediaAssetId;
    }
}
