<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

class AttachMediaToCardRequest extends FormRequest
{
    private bool $mediaAssetResolutionAttempted = false;

    private ?MediaAsset $resolvedMediaAsset = null;

    public function authorize(): bool
    {
        // FormRequest authorization runs before validation; use resolveMediaAsset() here later.
        // TODO: Replace with an ownership policy when API auth lands across flashcards/media.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'media_asset_id' => ['required', 'ulid'],
        ];
    }

    public function mediaAsset(): MediaAsset
    {
        $mediaAsset = $this->resolveMediaAsset();

        return $mediaAsset ?? throw new LogicException('mediaAsset() called before validation completed or outside a validated request context.');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media_asset_id')) {
                return;
            }

            // Resolve once during validation so the action receives loaded models.
            $mediaAsset = $this->resolveMediaAsset();

            if ($mediaAsset === null) {
                $validator->errors()->add('media_asset_id', 'The selected media asset id is invalid.');
            }
        });
    }

    private function resolveMediaAsset(): ?MediaAsset
    {
        if ($this->mediaAssetResolutionAttempted) {
            return $this->resolvedMediaAsset;
        }

        $this->mediaAssetResolutionAttempted = true;

        $mediaAssetId = $this->input('media_asset_id');

        if (! is_string($mediaAssetId)) {
            return null;
        }

        return $this->resolvedMediaAsset = MediaAsset::query()->find($mediaAssetId);
    }
}
