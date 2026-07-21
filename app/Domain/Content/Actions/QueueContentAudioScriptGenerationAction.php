<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptQueueException;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Jobs\ProcessContentAudioScriptGeneration;
use Throwable;

final readonly class QueueContentAudioScriptGenerationAction
{
    public function __construct(
        private StartContentAudioScriptGenerationAction $start,
        private FailContentAudioScriptGenerationAction $fail,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        string $episodeId,
        GenerateContentAudioScriptData $data,
    ): ContentAudioScriptGenerationJob {
        return $this->start->handle(
            $userId,
            $convoLabUserId,
            $episodeId,
            $data,
            function (string $jobId): void {
                try {
                    ProcessContentAudioScriptGeneration::dispatch($jobId);
                } catch (Throwable $exception) {
                    report($exception);
                    try {
                        $this->fail->handle($jobId, ContentAudioScriptJob::QUEUE_FAILED_MESSAGE);
                    } catch (Throwable $failureException) {
                        report($failureException);
                    }

                    throw new ContentAudioScriptQueueException(
                        ContentAudioScriptJob::QUEUE_FAILED_MESSAGE,
                        previous: $exception,
                    );
                }
            },
        );
    }
}
