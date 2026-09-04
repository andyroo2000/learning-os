<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\ClaimedContentAudioScriptGeneration;
use App\Domain\Content\Services\ContentAudioScriptGenerationClaimManager;
use App\Domain\Content\Services\ContentAudioScriptImageProcessor;
use App\Domain\Content\Services\ContentAudioScriptRenderProcessor;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptJobId;
use Throwable;

final readonly class ProcessContentAudioScriptGenerationAction
{
    public const MAX_IMAGE_BYTES = ContentAudioScriptImageProcessor::MAX_IMAGE_BYTES;

    public const IMAGE_FAILURE_MESSAGE = ContentAudioScriptImageProcessor::FAILURE_MESSAGE;

    public const IMAGE_BATCH_SIZE = ContentAudioScriptImageProcessor::BATCH_SIZE;

    public function __construct(
        private ContentAudioScriptGenerationClaimManager $claimManager,
        private ContentAudioScriptRenderProcessor $renderProcessor,
        private ContentAudioScriptImageProcessor $imageProcessor,
    ) {}

    public function handle(string $jobId): void
    {
        $jobId = ContentAudioScriptJobId::normalize($jobId);
        $claimed = $this->claimManager->claim($jobId);
        if ($claimed === null) {
            return;
        }

        try {
            $this->process($claimed);
        } catch (Throwable $exception) {
            $this->cleanUpFailedAttempt($claimed);

            throw $exception;
        }
    }

    private function process(ClaimedContentAudioScriptGeneration $claimed): void
    {
        if ($claimed->data->kind === ContentAudioScriptJob::KIND_RENDER) {
            $this->renderProcessor->process($claimed);

            return;
        }
        $this->imageProcessor->process($claimed);
    }

    private function cleanUpFailedAttempt(ClaimedContentAudioScriptGeneration $claimed): void
    {
        if ($claimed->data->kind === ContentAudioScriptJob::KIND_RENDER) {
            $this->renderProcessor->deleteAttemptPaths($claimed);
        }
        try {
            $this->claimManager->release($claimed->attempt->jobId);
        } catch (Throwable $releaseException) {
            report($releaseException);
        }
    }
}
