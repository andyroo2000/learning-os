<?php

namespace App\Http\Requests\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use LogicException;

class AttachMediaToCardRequest extends FormRequest
{
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
        // Validation owns existence lookup; the action enforces card/media ownership atomically.
        $mediaAsset = $this->resolveMediaAsset();

        return $mediaAsset ?? throw new LogicException('mediaAsset() called before validation completed or outside a validated request context.');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('media_asset_id')) {
                return;
            }

            // Use one lookup for existence validation and for the loaded action input.
            $mediaAsset = $this->resolveMediaAsset();

            if ($mediaAsset === null) {
                // Null means missing; existing cross-owner assets continue to the action for a 404.
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

        if ($this->user() === null) {
            // The route is authenticated; keep this resolver safe if reused earlier.
            return $this->resolvedMediaAsset = null;
        }

        $mediaAssetId = $this->input('media_asset_id');

        if (! is_string($mediaAssetId)) {
            // Protect future authorize() usage, which can run before validation.
            return $this->resolvedMediaAsset = null;
        }

        // Resolve by ID only; this intentionally moves ownership from validation to the action.
        return $this->resolvedMediaAsset = MediaAsset::query()
            ->whereKey($mediaAssetId)
            ->first();
    }
}
