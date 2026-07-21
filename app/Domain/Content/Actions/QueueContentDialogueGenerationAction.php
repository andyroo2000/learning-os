<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Exceptions\ContentDialogueGenerationQueueException;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Jobs\ProcessContentDialogueGeneration;
use Throwable;

final class QueueContentDialogueGenerationAction
{
    public function __construct(
        private readonly StartContentDialogueGenerationAction $start,
        private readonly FailContentDialogueGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        GenerateContentDialogueData $data,
    ): ?ContentDialogueGenerationJob {
        return $this->start->handle(
            $userId,
            $convoLabUserId,
            $data,
            function (string $jobId): void {
                try {
                    ProcessContentDialogueGeneration::dispatch($jobId);
                } catch (Throwable $exception) {
                    report($exception);
                    try {
                        $this->fail->handle($jobId, ContentDialogueGeneration::QUEUE_FAILED_MESSAGE);
                    } catch (Throwable $failureException) {
                        report($failureException);
                    }

                    throw new ContentDialogueGenerationQueueException(
                        ContentDialogueGeneration::QUEUE_FAILED_MESSAGE,
                        previous: $exception,
                    );
                }
            },
        );
    }
}
