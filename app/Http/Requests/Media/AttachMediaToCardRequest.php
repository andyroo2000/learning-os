<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use LogicException;

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

        throw new LogicException('Media asset has not been resolved by request validation.');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media_asset_id')) {
                return;
            }

            // Resolve once during validation so the action receives loaded models.
            $mediaAsset = MediaAsset::query()->find($this->input('media_asset_id'));

            if ($mediaAsset === null) {
                $validator->errors()->add('media_asset_id', 'The selected media asset id is invalid.');

                return;
            }

            $this->resolvedMediaAsset = $mediaAsset;
        });
    }
}
