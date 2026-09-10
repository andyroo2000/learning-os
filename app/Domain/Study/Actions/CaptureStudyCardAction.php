<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Results\CreateCardResult;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Study\Data\CaptureStudyCardData;
use App\Domain\Study\Support\StudyCaptureReplay;
use Illuminate\Support\Facades\DB;
use Throwable;

class CaptureStudyCardAction
{
    public function __construct(
        private readonly ResolveManualStudyDeckAction $deck,
        private readonly PersistStudyCaptureMediaAction $media,
        private readonly CreateCapturedStudyCardAction $create,
        private readonly NewCardQueuePosition $queue,
    ) {}

    public function handle(int $userId, CaptureStudyCardData $data, array $files): CreateCardResult
    {
        $media = [];
        try {
            return DB::transaction(function () use ($userId, $data, $files, &$media) {
                // Resolve replays before creating a deck, under the same owner lock used
                // by every queue writer. A retry must not create a new empty manual deck.
                $this->queue->lockOwner($userId);
                $existing = StudyCaptureReplay::find($userId, $data, $files);
                if ($existing !== null) {
                    return CreateCardResult::existing($existing);
                }
                $deck = $this->deck->handle($userId);
                $this->media->persist($userId, $files, $media);
                $card = $this->create->handle($userId, $deck->id, $data, $media);
                $this->media->attach($card, $media);

                return CreateCardResult::created($card);
            });
        } catch (Throwable $exception) {
            // Database rows/feed entries roll back together. Files require explicit cleanup.
            $this->media->discard($media);
            throw $exception;
        }
    }
}
