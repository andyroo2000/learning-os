<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Data\ClaimedContentAudioScriptGeneration;
use App\Domain\Content\Data\ContentAudioScriptGenerationAttempt;
use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ContentAudioScriptGenerationClaimManager
{
    public function claim(string $jobId): ?ClaimedContentAudioScriptGeneration
    {
        return DB::transaction(function () use ($jobId): ?ClaimedContentAudioScriptGeneration {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if (! $this->isClaimable($job)) {
                return null;
            }

            $script = ContentAudioScript::query()
                ->whereKey($job->script_id)
                ->with('episode')
                ->lockForUpdate()
                ->first();
            if (! $this->ownsAttempt($script, $job)) {
                $this->terminalizeSuperseded($job);

                return null;
            }

            $this->activate($job);
            $this->initialize($job, $script);

            return new ClaimedContentAudioScriptGeneration(
                GenerateContentAudioScriptData::fromJob($job->kind, $job->input),
                new ContentAudioScriptGenerationAttempt(
                    $jobId,
                    $script->id,
                    $script->episode_id,
                    (int) $job->attempt,
                ),
            );
        });
    }

    public function release(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE) {
                return;
            }
            $job->state = ContentAudioScriptJob::STATE_WAITING;
            $job->progress = 0;
            $job->started_at = null;
            $job->save();
        });
    }

    public function ownsAttempt(?ContentAudioScript $script, ContentAudioScriptGenerationJob $job): bool
    {
        if ($script === null || $script->episode_id !== $job->episode_id) {
            return false;
        }
        $attempt = $job->kind === ContentAudioScriptJob::KIND_RENDER
            ? $script->render_generation_attempt
            : $script->image_generation_attempt;

        return (int) $attempt === (int) $job->attempt;
    }

    public function terminalizeSuperseded(ContentAudioScriptGenerationJob $job): void
    {
        $job->state = ContentAudioScriptJob::STATE_FAILED;
        $job->error_message = ContentAudioScriptJob::FAILED_MESSAGE;
        $job->finished_at = now();
        $job->save();
    }

    private function isClaimable(?ContentAudioScriptGenerationJob $job): bool
    {
        if ($job === null || ContentAudioScriptJob::isTerminal($job->state)) {
            return false;
        }

        return $job->state !== ContentAudioScriptJob::STATE_ACTIVE
            || $job->started_at === null
            || ! $job->started_at->isAfter(now()->subSeconds(ContentAudioScriptJob::ACTIVE_STALE_AFTER_SECONDS));
    }

    private function activate(ContentAudioScriptGenerationJob $job): void
    {
        $job->state = ContentAudioScriptJob::STATE_ACTIVE;
        $job->progress = 5;
        $job->started_at = now();
        $job->save();
    }

    private function initialize(ContentAudioScriptGenerationJob $job, ContentAudioScript $script): void
    {
        if ($job->kind === ContentAudioScriptJob::KIND_RENDER) {
            $this->initializeRenders($script);

            return;
        }
        $this->initializeImages($job, $script);
    }

    private function initializeRenders(ContentAudioScript $script): void
    {
        foreach (ContentAudioScriptRenderAudio::SPEEDS as $speed) {
            $render = ContentAudioScriptRender::query()
                ->where('script_id', $script->id)
                ->where('speed', $speed['speed'])
                ->first();
            if ($render === null) {
                $render = new ContentAudioScriptRender;
                $render->id = (string) Str::uuid();
                $render->script_id = $script->id;
                $render->speed = $speed['speed'];
            }
            $render->numeric_speed = $speed['numericSpeed'];
            $render->status = 'generating';
            $render->error_message = null;
            $render->save();
        }
    }

    private function initializeImages(ContentAudioScriptGenerationJob $job, ContentAudioScript $script): void
    {
        if (data_get($job->result, 'initialized') === true) {
            return;
        }
        $targets = $script->segments()
            ->when(! (bool) data_get($job->input, 'force', false), fn ($query) => $query
                ->where(fn ($query) => $query
                    ->where('image_status', '!=', 'ready')
                    ->orWhereNull('image_media_id')))
            ->pluck('id');
        if ($targets->isNotEmpty()) {
            ContentAudioScriptSegment::query()->whereKey($targets)->update([
                'image_status' => 'generating',
                'image_error_message' => null,
            ]);
        }
        $job->result = ['initialized' => true, 'targetCount' => $targets->count()];
        $job->save();
    }
}
