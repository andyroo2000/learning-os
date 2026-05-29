<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
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
        $mediaAssetId = $this->route('mediaAsset');

        if (! is_string($mediaAssetId) || $mediaAssetId === '') {
            throw (new ModelNotFoundException)->setModel(MediaAsset::class, [$mediaAssetId]);
        }

        return $mediaAssetId;
    }
}
