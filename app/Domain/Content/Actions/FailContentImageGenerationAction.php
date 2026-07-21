<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentImageGenerationJobId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FailContentImageGenerationAction
{
    public function handle(string $jobId, string $message): bool
    {
        $jobId = ContentImageGenerationJobId::normalize($jobId);
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Image generation failure requires a message.');
        }

        return DB::transaction(function () use ($jobId, $message): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentImageGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentImageGeneration::isTerminal($job->state)) {
                return false;
            }

            $job->state = ContentImageGeneration::STATE_FAILED;
            $job->claim_token = null;
            $job->error_message = $message;
            $job->finished_at = now();
            $job->save();

            return true;
        });
    }
}
