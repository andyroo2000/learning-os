<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptJobId;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final class FailContentAudioScriptGenerationAction
{
    public function handle(string $jobId, string $message): bool
    {
        $jobId = ContentAudioScriptJobId::normalize($jobId);
        $message = trim($message);
        if ($message === '') {
            throw new InvalidArgumentException('Script generation failure requires a message.');
        }

        $cleanup = DB::transaction(function () use ($jobId, $message): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentAudioScriptJob::isTerminal($job->state)) {
                return null;
            }

            $job->state = ContentAudioScriptJob::STATE_FAILED;
            $job->error_message = $message;
            $job->finished_at = now();
            $job->save();

            $script = ContentAudioScript::query()
                ->whereKey($job->script_id)
                ->with('episode')
                ->lockForUpdate()
                ->first();
            if ($script === null || ! $this->ownsAttempt($script, $job)) {
                return [];
            }

            if ($job->kind === ContentAudioScriptJob::KIND_RENDER) {
                $script->status = 'error';
                $script->error_message = $message;
                $script->save();
                $script->episode->status = 'error';
                $script->episode->save();
                ContentAudioScriptRender::query()
                    ->where('script_id', $script->id)
                    ->where('status', 'generating')
                    ->update(['status' => 'error', 'error_message' => $message]);

                return [$script->episode_id, (int) $job->attempt];
            }

            $script->image_status = 'error';
            $script->image_error_message = $message;
            $script->save();
            ContentAudioScriptSegment::query()
                ->where('script_id', $script->id)
                ->where('image_status', 'generating')
                ->update(['image_status' => 'error', 'image_error_message' => $message]);

            return [];
        });

        if (count($cleanup ?? []) === 2) {
            [$episodeId, $attempt] = $cleanup;
            $disk = Storage::disk((string) config('content_audio.disk'));
            foreach (ContentAudioScriptRenderAudio::SPEEDS as $speed) {
                try {
                    $disk->delete(ContentAudioScriptRenderAudio::storagePath($episodeId, $attempt, $speed['speed']));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $cleanup !== null;
    }

    private function ownsAttempt(ContentAudioScript $script, ContentAudioScriptGenerationJob $job): bool
    {
        $attempt = $job->kind === ContentAudioScriptJob::KIND_RENDER
            ? $script->render_generation_attempt
            : $script->image_generation_attempt;

        return $script->episode_id === $job->episode_id && (int) $attempt === (int) $job->attempt;
    }
}
