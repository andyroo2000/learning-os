<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\DetachMediaFromCardData;

class DetachMediaFromCardAction
{
    public function handle(DetachMediaFromCardData $data): Card
    {
        $data->card->mediaAssets()->detach($data->mediaAsset->id);

        return $data->card->load('mediaAssets');
    }
}
