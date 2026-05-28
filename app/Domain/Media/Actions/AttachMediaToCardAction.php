<?php

namespace App\Domain\Media\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Data\AttachMediaToCardData;

class AttachMediaToCardAction
{
    public function handle(AttachMediaToCardData $data): Card
    {
        $data->card->mediaAssets()->syncWithoutDetaching([$data->mediaAsset->id]);

        return $data->card->load('mediaAssets');
    }
}
