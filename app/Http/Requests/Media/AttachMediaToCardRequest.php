<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AttachMediaToCardRequest extends FormRequest
{
    private ?MediaAsset $resolvedMediaAsset = null;

    public function authorize(): bool
    {
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
        if ($this->resolvedMediaAsset !== null) {
            return $this->resolvedMediaAsset;
        }

        $mediaAsset = MediaAsset::query()->find($this->validated('media_asset_id'));

        if ($mediaAsset === null) {
            throw ValidationException::withMessages([
                'media_asset_id' => ['The selected media asset id is invalid.'],
            ]);
        }

        return $this->resolvedMediaAsset = $mediaAsset;
    }
}
