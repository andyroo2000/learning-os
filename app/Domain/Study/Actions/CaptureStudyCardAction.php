<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Results\CreateCardResult;
use App\Domain\Flashcards\Support\NewCardQueuePosition;
use App\Domain\Study\Data\CaptureStudyCardData;
use App\Domain\Study\Services\StudyCaptureReadingGenerator;
use App\Domain\Study\Support\StudyCaptureReplay;
use Illuminate\Support\Facades\DB;
use Throwable;

class CaptureStudyCardAction
{
    public function __construct(
        private readonly PersistStudyCaptureMediaAction $media,
        private readonly CreateCapturedStudyCardAction $create,
        private readonly NewCardQueuePosition $queue,
        private readonly StudyCaptureReadingGenerator $readings,
    ) {}

    public function handle(int $userId, CaptureStudyCardData $data, array $files): CreateCardResult
    {
        // Avoid provider calls on transport retries. Recheck under the owner lock below
        // because a concurrent capture can commit while the reading is generated.
        $existing = StudyCaptureReplay::find($userId, $data, $files);
        if ($existing !== null) {
            return CreateCardResult::existing($existing);
        }
        $reading = $this->readings->generate($data->answer['expression']);
        $media = [];
        try {
            return DB::transaction(function () use ($userId, $data, $files, &$media, $reading) {
                // Resolve replays before creating a deck, under the same owner lock used
                // by every queue writer. A retry must not create a new empty manual deck.
                $this->queue->lockOwner($userId);
                $existing = StudyCaptureReplay::find($userId, $data, $files);
                if ($existing !== null) {
                    return CreateCardResult::existing($existing);
                }
                $this->media->persist($userId, $files, $media);
                $card = $this->create->handle($userId, $data, $media, $reading);
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
