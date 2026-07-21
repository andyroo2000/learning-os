<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentImagesData;
use App\Domain\Content\Exceptions\ContentImageGenerationQueueException;
use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Jobs\ProcessContentImageGeneration;
use Throwable;

final readonly class QueueContentImageGenerationAction
{
    public function __construct(
        private StartContentImageGenerationAction $start,
        private FailContentImageGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        GenerateContentImagesData $data,
    ): ?ContentImageGenerationJob {
        return $this->start->handle(
            $userId,
            $convoLabUserId,
            $data,
            function (string $jobId): void {
                try {
                    ProcessContentImageGeneration::dispatch($jobId);
                } catch (Throwable $exception) {
                    report($exception);
                    try {
                        $this->fail->handle($jobId, ContentImageGeneration::QUEUE_FAILED_MESSAGE);
                    } catch (Throwable $failureException) {
                        report($failureException);
                    }

                    throw new ContentImageGenerationQueueException(
                        ContentImageGeneration::QUEUE_FAILED_MESSAGE,
                        previous: $exception,
                    );
                }
            },
        );
    }
}
