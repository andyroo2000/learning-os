<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Actions\DetachMediaFromCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Data\DetachMediaFromCardData;
use App\Domain\Media\Models\MediaAsset;

class ReplaceStudyCardAudioMediaAction
{
    public function __construct(
        private readonly AttachMediaToCardAction $attach,
        private readonly DetachMediaFromCardAction $detach,
        private readonly DiscardGeneratedStudyMediaAction $discard,
    ) {}

    public function attachReplacement(Card $card, MediaAsset $newMedia, iterable $oldMedia): void
    {
        $this->attach->handle(AttachMediaToCardData::fromModels($card, $newMedia));
        foreach ($oldMedia as $media) {
            $this->detach->handle(DetachMediaFromCardData::fromModels($card, $media));
        }
    }

    public function discardFailedUpload(MediaAsset $media): void
    {
        $this->discard->handle($media);
    }

    public function discardUnreferenced(iterable $oldMedia): void
    {
        foreach ($oldMedia as $media) {
            $this->discard->handleIfUnreferenced($media);
        }
    }
}
