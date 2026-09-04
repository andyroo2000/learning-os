<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Data\ClaimedContentAudioScriptGeneration;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Results\ContentAudioScriptRenderResult;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class ContentAudioScriptRenderProcessor
{
    public function __construct(
        private ContentAudioScriptRenderAssembler $audioAssembler,
        private ContentAudioScriptGenerationClaimManager $claimManager,
    ) {}

    public function process(ClaimedContentAudioScriptGeneration $claim): void
    {
        $script = ContentAudioScript::query()
            ->whereKey($claim->attempt->scriptId)
            ->with(['segments' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->firstOrFail();
        $results = $this->assemble($script, $claim);
        $oldPaths = $this->complete($claim, $results);
        if ($oldPaths === null) {
            $this->deleteAttemptPaths($claim);

            return;
        }
        $this->deleteOwnedPaths($script->episode_id, $oldPaths);
    }

    public function deleteAttemptPaths(ClaimedContentAudioScriptGeneration $claim): void
    {
        $paths = array_map(
            fn (array $speed): string => ContentAudioScriptRenderAudio::storagePath(
                $claim->attempt->episodeId,
                $claim->attempt->number,
                $speed['speed'],
            ),
            ContentAudioScriptRenderAudio::SPEEDS,
        );
        $this->deleteOwnedPaths($claim->attempt->episodeId, $paths);
    }

    /** @return list<ContentAudioScriptRenderResult> */
    private function assemble(ContentAudioScript $script, ClaimedContentAudioScriptGeneration $claim): array
    {
        $results = [];
        foreach (ContentAudioScriptRenderAudio::SPEEDS as $index => $speed) {
            $results[] = $this->audioAssembler->assemble(
                $script,
                $claim->attempt->number,
                $speed['speed'],
                $speed['numericSpeed'],
            );
            $this->progress($claim, 10 + (int) round((($index + 1) / count(ContentAudioScriptRenderAudio::SPEEDS)) * 85));
        }

        return $results;
    }

    /** @param list<ContentAudioScriptRenderResult> $results */
    private function complete(ClaimedContentAudioScriptGeneration $claim, array $results): ?array
    {
        return DB::transaction(function () use ($claim, $results): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($claim->attempt->jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()
                ->whereKey($job->script_id)
                ->with('episode')
                ->lockForUpdate()
                ->first();
            if (! $this->canComplete($job, $script)) {
                $this->terminalizeActiveJob($job);

                return null;
            }

            $oldPaths = $this->persistResults($script, $results);
            $this->markReady($script, $job);

            return array_values(array_unique($oldPaths));
        });
    }

    private function canComplete(?ContentAudioScriptGenerationJob $job, ?ContentAudioScript $script): bool
    {
        return $job !== null
            && $job->state === ContentAudioScriptJob::STATE_ACTIVE
            && $this->claimManager->ownsAttempt($script, $job);
    }

    private function terminalizeActiveJob(?ContentAudioScriptGenerationJob $job): void
    {
        if ($job !== null && ! ContentAudioScriptJob::isTerminal($job->state)) {
            $this->claimManager->terminalizeSuperseded($job);
        }
    }

    /**
     * @param  list<ContentAudioScriptRenderResult>  $results
     * @return list<string>
     */
    private function persistResults(ContentAudioScript $script, array $results): array
    {
        $oldPaths = [];
        foreach ($results as $result) {
            $render = ContentAudioScriptRender::query()
                ->where('script_id', $script->id)
                ->where('speed', $result->speed)
                ->lockForUpdate()
                ->firstOrFail();
            if (is_string($render->audio_storage_path) && $render->audio_storage_path !== $result->storagePath) {
                $oldPaths[] = $render->audio_storage_path;
            }
            $render->numeric_speed = $result->numericSpeed;
            $render->status = 'ready';
            $render->audio_url = ContentAudioScriptRenderAudio::audioUrl($script->episode_id, $render->id);
            $render->audio_storage_path = $result->storagePath;
            $render->timing_data = $result->timingData;
            $render->approx_duration_seconds = $result->durationSeconds;
            $render->error_message = null;
            $render->save();
        }

        return $oldPaths;
    }

    private function markReady(ContentAudioScript $script, ContentAudioScriptGenerationJob $job): void
    {
        $script->status = 'ready';
        $script->error_message = null;
        $script->save();
        $script->episode->status = 'ready';
        $script->episode->save();

        $job->state = ContentAudioScriptJob::STATE_COMPLETED;
        $job->progress = 100;
        $job->result = ['episodeId' => $script->episode_id, 'status' => 'ready'];
        $job->error_message = null;
        $job->finished_at = now();
        $job->save();
    }

    private function progress(ClaimedContentAudioScriptGeneration $claim, int $progress): void
    {
        ContentAudioScriptGenerationJob::query()
            ->whereKey($claim->attempt->jobId)
            ->where('state', ContentAudioScriptJob::STATE_ACTIVE)
            ->update(['progress' => min(99, max(5, $progress))]);
    }

    /** @param list<string|null> $paths */
    private function deleteOwnedPaths(string $episodeId, array $paths): void
    {
        $disk = Storage::disk((string) config('content_audio.disk'));
        foreach (array_unique($paths) as $path) {
            if (! ContentAudioScriptRenderAudio::ownsPath($episodeId, $path)) {
                continue;
            }
            try {
                $disk->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
