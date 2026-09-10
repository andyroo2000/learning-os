<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Actions\AttachMediaToCardAction;
use App\Domain\Media\Data\AttachMediaToCardData;
use Illuminate\Http\UploadedFile;

class PersistStudyCaptureMediaAction
{
    public function __construct(
        private readonly PersistUploadedStudyAudioAction $audio,
        private readonly PersistUploadedStudyImageAction $image,
        private readonly AttachMediaToCardAction $attach,
        private readonly DiscardGeneratedStudyMediaAction $discard,
    ) {}

    // The caller tracks each successful write so a later failure cleans up both files.
    public function persist(int $userId, array $files, array &$media): void
    {
        $media['audio'] = $this->audio->handle($userId, $files['audio']);
        if (($files['image'] ?? null) instanceof UploadedFile) {
            $media['image'] = $this->image->handle($userId, $files['image']);
        }
    }

    public function attach(Card $card, array $media): void
    {
        foreach ($media as $item) {
            $this->attach->handle(AttachMediaToCardData::fromModels($card, $item->mediaAsset));
        }
    }

    public function discard(array $media): void
    {
        foreach ($media as $item) {
            $this->discard->handle($item->mediaAsset);
        }
    }
}
