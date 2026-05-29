<?php

namespace App\Http\Requests\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

class DetachMediaFromCardRequest extends FormRequest
{
    private bool $mediaAssetResolutionAttempted = false;

    private ?MediaAsset $resolvedMediaAsset = null;

    public function authorize(): bool
    {
        /** @var Card $card */
        $card = $this->route('card');

        // Throw via Gate so CardPolicy's 404 denial is preserved; returning false here would become a 403.
        Gate::authorize('update', $card);

        if ($this->resolveMediaAsset() === null) {
            throw (new ModelNotFoundException)->setModel(MediaAsset::class, [$this->route('mediaAsset')]);
        }

        return true;
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

        if (! is_string($mediaAssetId)) {
            return $this->resolvedMediaAsset = null;
        }

        $userId = $this->user()?->id;

        if ($userId === null) {
            return $this->resolvedMediaAsset = null;
        }

        return $this->resolvedMediaAsset = MediaAsset::query()
            ->whereKey($mediaAssetId)
            ->where('user_id', $userId)
            ->first();
    }
}
