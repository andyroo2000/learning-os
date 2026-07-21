<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioData;
use App\Domain\Content\Exceptions\ContentAudioGenerationQueueException;
use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Jobs\ProcessContentAudioGeneration;
use Throwable;

final readonly class QueueContentAudioGenerationAction
{
    public function __construct(
        private StartContentAudioGenerationAction $start,
        private FailContentAudioGenerationAction $fail,
    ) {}

    public function handle(int $userId, string $convoLabUserId, GenerateContentAudioData $data): ?ContentAudioGenerationJob
    {
        return $this->start->handle(
            $userId,
            $convoLabUserId,
            $data,
            function (string $jobId): void {
                try {
                    ProcessContentAudioGeneration::dispatch($jobId);
                } catch (Throwable $exception) {
                    report($exception);
                    try {
                        $this->fail->handle($jobId, ContentAudioGeneration::QUEUE_FAILED_MESSAGE);
                    } catch (Throwable $failureException) {
                        report($failureException);
                    }

                    throw new ContentAudioGenerationQueueException(
                        ContentAudioGeneration::QUEUE_FAILED_MESSAGE,
                        previous: $exception,
                    );
                }
            },
        );
    }
}
