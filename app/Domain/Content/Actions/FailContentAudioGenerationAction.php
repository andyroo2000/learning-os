<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentAudioJobId;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class FailContentAudioGenerationAction
{
    public function handle(string $jobId, string $message): bool
    {
        $jobId = ContentAudioJobId::normalize($jobId);
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Audio generation failure requires a message.');
        }

        $failed = DB::transaction(function () use ($jobId, $message): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentAudioGeneration::isTerminal($job->state)) {
                return false;
            }

            $job->state = ContentAudioGeneration::STATE_FAILED;
            $job->error_message = $message;
            $job->finished_at = now();
            $job->save();

            return true;
        });

        if ($failed) {
            try {
                $job = ContentAudioGenerationJob::query()->find($jobId);
                if ($job !== null) {
                    $disk = Storage::disk((string) config('content_audio.disk'));
                    foreach (ContentEpisodeAudio::tracks() as $track) {
                        try {
                            $disk->delete(ContentEpisodeAudio::storagePath(
                                $job->episode_id,
                                (int) $job->attempt,
                                $track,
                            ));
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $failed;
    }
}
