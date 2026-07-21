<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueJobId;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FailContentDialogueGenerationAction
{
    public function handle(string $jobId, string $message): bool
    {
        $jobId = ContentDialogueJobId::normalize($jobId);
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Dialogue generation failure requires a message.');
        }

        return DB::transaction(function () use ($jobId, $message): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentDialogueGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentDialogueGeneration::isTerminal($job->state)) {
                return false;
            }

            $finishedAt = now();
            $job->state = ContentDialogueGeneration::STATE_FAILED;
            $job->error_message = $message;
            $job->finished_at = $finishedAt;
            $job->save();

            $episode = ContentEpisode::query()->whereKey($job->episode_id)->lockForUpdate()->first();
            if ($episode !== null && $episode->status === 'generating'
                && (int) $episode->dialogue_generation_attempt === (int) $job->attempt) {
                $episode->status = 'error';
                $episode->save();
            }

            return true;
        });
    }
}
