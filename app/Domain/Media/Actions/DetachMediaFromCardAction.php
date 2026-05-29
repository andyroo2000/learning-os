<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\DetachMediaFromCardData;

class DetachMediaFromCardAction
{
    public function handle(DetachMediaFromCardData $data): Card
    {
        $detachedCount = $data->card->mediaAssets()->detach($data->mediaAsset->id);

        if ($detachedCount > 0) {
            $data->card->touch();
        }

        return $data->card->load('mediaAssets');
    }
}
