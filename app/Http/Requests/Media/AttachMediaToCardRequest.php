<?php

namespace App\Http\Requests\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Requests\Concerns\NormalizesUlidInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;

class AttachMediaToCardRequest extends FormRequest
{
    use NormalizesUlidInput;

    private bool $mediaAssetResolutionAttempted = false;

    private ?MediaAsset $resolvedMediaAsset = null;

    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        // Throw via Gate so CardPolicy's 404 denial is preserved; returning false here would become a 403.
        Gate::authorize('update', $card);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = [];

        $this->mergeNormalizedUlidInput($input, 'media_asset_id');

        $this->merge($input);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Validate presence and shape here; withValidator checks existence, and the action owns ownership.
            'media_asset_id' => ['required', 'ulid'],
        ];
    }

    public function mediaAsset(): MediaAsset
    {
        if (! $this->mediaAssetResolutionAttempted) {
            $mediaAssetId = $this->validated('media_asset_id');

            if (! is_string($mediaAssetId)) {
                throw new LogicException('mediaAsset() called before validation completed or outside a validated request context.');
            }

            $this->resolveMediaAssetById($mediaAssetId);
        }

        return $this->resolvedMediaAsset ?? throw new LogicException('mediaAsset() called before validation completed or outside a validated request context.');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media_asset_id')) {
                return;
            }

            // validated() can throw inside after callbacks when another field fails; this field passed above.
            $mediaAssetId = $validator->getData()['media_asset_id'] ?? null;

            if (! is_string($mediaAssetId)) {
                return;
            }

            // Use one lookup for existence validation and for the loaded action input.
            $mediaAsset = $this->resolveMediaAssetById($mediaAssetId);

            if ($mediaAsset === null) {
                // Null means missing; existing cross-owner assets continue to the action for a 404.
                $validator->errors()->add('media_asset_id', 'The selected media asset id is invalid.');
            }
        });
    }

    private function resolveMediaAssetById(string $mediaAssetId): ?MediaAsset
    {
        if ($this->mediaAssetResolutionAttempted) {
            return $this->resolvedMediaAsset;
        }

        $this->mediaAssetResolutionAttempted = true;

        if ($this->user() === null) {
            // The route is authenticated; keep this resolver safe if reused earlier.
            return $this->resolvedMediaAsset = null;
        }

        // Resolve by ID only; this intentionally moves ownership from validation to the action.
        return $this->resolvedMediaAsset = MediaAsset::query()
            ->whereKey($mediaAssetId)
            ->first();
    }
}
