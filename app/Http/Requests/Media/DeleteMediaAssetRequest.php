<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

class DeleteMediaAssetRequest extends FormRequest
{
    private bool $mediaAssetResolutionAttempted = false;

    private ?MediaAsset $resolvedMediaAsset = null;

    public function authorize(): bool
    {
        if ($this->resolveMediaAsset() === null) {
            throw (new ModelNotFoundException)->setModel(MediaAsset::class, [$this->route('mediaAsset')]);
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function mediaAsset(): MediaAsset
    {
        $mediaAsset = $this->resolveMediaAsset();

        return $mediaAsset ?? throw new LogicException('mediaAsset() called before authorization completed or outside a validated request context.');
    }

    private function resolveMediaAsset(): ?MediaAsset
    {
        if ($this->mediaAssetResolutionAttempted) {
            return $this->resolvedMediaAsset;
        }

        $this->mediaAssetResolutionAttempted = true;

        $mediaAssetId = $this->route('mediaAsset');

        // Intentionally resolve the raw route segment here so media assets are scoped to the current user.
        if ($mediaAssetId === null) {
            return $this->resolvedMediaAsset = null;
        }

        return $this->resolvedMediaAsset = MediaAsset::query()
            ->whereKey($mediaAssetId)
            ->where('user_id', $this->user()->id)
            ->first();
    }
}
