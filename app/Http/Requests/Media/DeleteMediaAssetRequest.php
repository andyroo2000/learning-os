<?php

namespace App\Http\Requests\Media;

use Illuminate\Auth\AuthenticationException;
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
        $user = $this->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        return $user->id;
    }

    public function mediaAssetId(): string
    {
        /** @var string $mediaAssetId */
        $mediaAssetId = $this->route('mediaAsset');

        return $mediaAssetId;
    }
}
