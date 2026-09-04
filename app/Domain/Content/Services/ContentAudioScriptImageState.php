<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Data\ClaimedContentAudioScriptGeneration;
use App\Domain\Content\Data\GeneratedContentAudioScriptImage;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioScriptGeneration;
use Illuminate\Support\Facades\DB;

final readonly class ContentAudioScriptImageState
{
    public function __construct(
        private ContentAudioScriptGenerationClaimManager $claimManager,
        private ContentAudioScriptMediaCleaner $mediaCleaner,
    ) {}

    public function persist(GeneratedContentAudioScriptImage $image): bool
    {
        $oldPath = null;
        $accepted = DB::transaction(function () use ($image, &$oldPath): bool {
            [$job, $script] = $this->lockJobAndScript($image->claim);
            if (! $this->isActiveOwner($job, $script)) {
                return false;
            }

            $segment = ContentAudioScriptSegment::query()
                ->whereKey($image->segment->id)
                ->where('script_id', $script->id)
                ->lockForUpdate()
                ->first();
            if ($segment === null) {
                $this->claimManager->terminalizeSuperseded($job);

                return false;
            }

            $oldMedia = $segment->imageMedia()->lockForUpdate()->first();
            $this->replaceMedia($segment, $job, $image);
            $oldPath = $this->deleteUnreferencedMedia($oldMedia);

            return true;
        });
        if ($accepted && is_string($oldPath)) {
            $this->mediaCleaner->deleteFiles([$oldPath]);
        }

        return $accepted;
    }

    public function recordFailure(
        ClaimedContentAudioScriptGeneration $claim,
        ContentAudioScriptSegment $segment,
    ): void {
        DB::transaction(function () use ($claim, $segment): void {
            [$job, $script] = $this->lockJobAndScript($claim);
            if (! $this->isActiveOwner($job, $script)) {
                return;
            }

            ContentAudioScriptSegment::query()
                ->whereKey($segment->id)
                ->where('script_id', $script->id)
                ->update([
                    'image_status' => 'error',
                    'image_error_message' => ContentAudioScriptImageProcessor::FAILURE_MESSAGE,
                ]);
        });
    }

    public function complete(ClaimedContentAudioScriptGeneration $claim): void
    {
        DB::transaction(function () use ($claim): void {
            [$job, $script] = $this->lockJobAndScript($claim);
            if (! $this->isActiveOwner($job, $script)) {
                $this->terminalizeActiveJob($job);

                return;
            }

            [$status, $message] = $this->imageOutcome($script);
            $script->image_status = $status;
            $script->image_error_message = $message;
            $script->save();

            $job->state = ContentAudioScriptJob::STATE_COMPLETED;
            $job->progress = 100;
            $job->result = ['episodeId' => $script->episode_id, 'imageStatus' => $status];
            $job->error_message = null;
            $job->finished_at = now();
            $job->save();
        });
    }

    public function updateProgress(ClaimedContentAudioScriptGeneration $claim): void
    {
        $job = ContentAudioScriptGenerationJob::query()->whereKey($claim->attempt->jobId)->first();
        $targetCount = (int) data_get($job?->result, 'targetCount', 0);
        if ($job === null || $targetCount < 1) {
            return;
        }
        $remaining = ContentAudioScriptSegment::query()
            ->where('script_id', $job->script_id)
            ->where('image_status', 'generating')
            ->count();
        $processed = max(0, $targetCount - $remaining);
        $progress = 10 + (int) round(($processed / $targetCount) * 85);
        ContentAudioScriptGenerationJob::query()
            ->whereKey($claim->attempt->jobId)
            ->where('state', ContentAudioScriptJob::STATE_ACTIVE)
            ->update(['progress' => min(99, max(5, $progress))]);
    }

    public function requeue(ClaimedContentAudioScriptGeneration $claim): void
    {
        DB::transaction(function () use ($claim): void {
            [$job, $script] = $this->lockJobAndScript($claim);
            if (! $this->isActiveOwner($job, $script)) {
                return;
            }

            $job->state = ContentAudioScriptJob::STATE_WAITING;
            $job->started_at = null;
            $job->save();

            DB::afterCommit(static fn () => ProcessContentAudioScriptGeneration::dispatch($claim->attempt->jobId));
        });
    }

    /** @return array{?ContentAudioScriptGenerationJob, ?ContentAudioScript} */
    private function lockJobAndScript(ClaimedContentAudioScriptGeneration $claim): array
    {
        ContentSourceLock::acquireConvoLab(DB::connection());
        $job = ContentAudioScriptGenerationJob::query()->whereKey($claim->attempt->jobId)->lockForUpdate()->first();
        $script = $job === null ? null : ContentAudioScript::query()->whereKey($job->script_id)->lockForUpdate()->first();

        return [$job, $script];
    }

    private function isActiveOwner(?ContentAudioScriptGenerationJob $job, ?ContentAudioScript $script): bool
    {
        return $job !== null
            && $job->state === ContentAudioScriptJob::STATE_ACTIVE
            && $this->claimManager->ownsAttempt($script, $job);
    }

    private function replaceMedia(
        ContentAudioScriptSegment $segment,
        ContentAudioScriptGenerationJob $job,
        GeneratedContentAudioScriptImage $image,
    ): void {
        $media = new ContentAudioScriptMedia;
        $media->id = $image->mediaId;
        $media->user_id = $job->user_id;
        $media->source_system = ContentSourceSystem::LEARNING_OS;
        $media->source_kind = 'generated';
        $media->source_filename = basename($image->path);
        $media->normalized_filename = basename($image->path);
        $media->media_kind = 'image';
        $media->content_type = 'image/webp';
        $media->storage_path = $image->path;
        $media->public_url = "/api/convolab/scripts/media/{$media->id}";
        $media->save();

        $segment->image_media_id = $media->id;
        $segment->image_status = 'ready';
        $segment->image_error_message = null;
        $segment->image_generated_at = now();
        $segment->save();
    }

    private function deleteUnreferencedMedia(?ContentAudioScriptMedia $oldMedia): ?string
    {
        if ($oldMedia === null) {
            return null;
        }
        if ($oldMedia->source_kind !== 'generated') {
            return null;
        }
        if ($oldMedia->media_kind !== 'image') {
            return null;
        }
        if ($oldMedia->segments()->exists()) {
            return null;
        }
        $oldPath = $oldMedia->storage_path;
        $oldMedia->delete();

        return $oldPath;
    }

    /** @return array{string, ?string} */
    private function imageOutcome(ContentAudioScript $script): array
    {
        $total = $script->segments()->count();
        $ready = $script->segments()->where('image_status', 'ready')->whereNotNull('image_media_id')->count();
        $status = $ready === $total ? 'ready' : ($ready > 0 ? 'partial' : 'error');
        $failed = $total - $ready;
        $message = match ($status) {
            'partial' => "{$failed} script image(s) failed or are missing.",
            'error' => 'Script image generation failed.',
            default => null,
        };

        return [$status, $message];
    }

    private function terminalizeActiveJob(?ContentAudioScriptGenerationJob $job): void
    {
        if ($job !== null && ! ContentAudioScriptJob::isTerminal($job->state)) {
            $this->claimManager->terminalizeSuperseded($job);
        }
    }
}
