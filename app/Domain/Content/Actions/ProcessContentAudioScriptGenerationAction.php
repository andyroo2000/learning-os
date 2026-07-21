<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptRender;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Results\ContentAudioScriptRenderResult;
use App\Domain\Content\Services\ContentAudioScriptMediaCleaner;
use App\Domain\Content\Services\ContentAudioScriptRenderAssembler;
use App\Domain\Content\Support\ContentAudioScriptGeneratedImagePath;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentAudioScriptJobId;
use App\Domain\Content\Support\ContentAudioScriptRenderAudio;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Jobs\ProcessContentAudioScriptGeneration;
use App\Support\Images\ImageGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProcessContentAudioScriptGenerationAction
{
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public const IMAGE_FAILURE_MESSAGE = 'Script image generation failed.';

    public const IMAGE_BATCH_SIZE = 40;

    public function __construct(
        private ContentAudioScriptRenderAssembler $audioAssembler,
        private ImageGenerator $imageGenerator,
        private ContentAudioScriptMediaCleaner $mediaCleaner,
    ) {}

    public function handle(string $jobId): void
    {
        $jobId = ContentAudioScriptJobId::normalize($jobId);
        $claimed = $this->claim($jobId);
        if ($claimed === null) {
            return;
        }

        try {
            if ($claimed['data']->kind === ContentAudioScriptJob::KIND_RENDER) {
                $this->processRender($jobId, $claimed['scriptId'], $claimed['attempt']);
            } else {
                $this->processImages(
                    $jobId,
                    $claimed['scriptId'],
                    $claimed['episodeId'],
                    $claimed['attempt'],
                );
            }
        } catch (Throwable $exception) {
            if ($claimed['data']->kind === ContentAudioScriptJob::KIND_RENDER) {
                $this->deleteRenderPaths($claimed['episodeId'], $claimed['attempt']);
            }
            try {
                $this->releaseClaim($jobId);
            } catch (Throwable $releaseException) {
                report($releaseException);
            }

            throw $exception;
        }
    }

    /** @return null|array{data: GenerateContentAudioScriptData, scriptId: string, episodeId: string, attempt: int} */
    private function claim(string $jobId): ?array
    {
        return DB::transaction(function () use ($jobId): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentAudioScriptJob::isTerminal($job->state)) {
                return null;
            }
            if ($job->state === ContentAudioScriptJob::STATE_ACTIVE
                && $job->started_at !== null
                && $job->started_at->isAfter(now()->subSeconds(ContentAudioScriptJob::ACTIVE_STALE_AFTER_SECONDS))) {
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

            $job->state = ContentAudioScriptJob::STATE_ACTIVE;
            $job->progress = 5;
            $job->started_at = now();
            $job->save();

            if ($job->kind === ContentAudioScriptJob::KIND_RENDER) {
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
            } else {
                if (data_get($job->result, 'initialized') !== true) {
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

            return [
                'data' => GenerateContentAudioScriptData::fromJob($job->kind, $job->input),
                'scriptId' => $script->id,
                'episodeId' => $script->episode_id,
                'attempt' => (int) $job->attempt,
            ];
        });
    }

    private function processRender(string $jobId, string $scriptId, int $attempt): void
    {
        $script = ContentAudioScript::query()
            ->whereKey($scriptId)
            ->with(['segments' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->firstOrFail();
        $results = [];
        foreach (ContentAudioScriptRenderAudio::SPEEDS as $index => $speed) {
            $results[] = $this->audioAssembler->assemble(
                $script,
                $attempt,
                $speed['speed'],
                $speed['numericSpeed'],
            );
            $this->progress($jobId, 10 + (int) round((($index + 1) / count(ContentAudioScriptRenderAudio::SPEEDS)) * 85));
        }

        $oldPaths = $this->completeRender($jobId, $results);
        if ($oldPaths === null) {
            $this->deleteRenderPaths($script->episode_id, $attempt);

            return;
        }
        $this->deleteOwnedRenderPaths($script->episode_id, $oldPaths);
    }

    /** @param list<ContentAudioScriptRenderResult> $results */
    private function completeRender(string $jobId, array $results): ?array
    {
        return DB::transaction(function () use ($jobId, $results): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()
                ->whereKey($job->script_id)
                ->with('episode')
                ->lockForUpdate()
                ->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE || ! $this->ownsAttempt($script, $job)) {
                if ($job !== null && ! ContentAudioScriptJob::isTerminal($job->state)) {
                    $this->terminalizeSuperseded($job);
                }

                return null;
            }

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

            return array_values(array_unique($oldPaths));
        });
    }

    private function processImages(
        string $jobId,
        string $scriptId,
        string $episodeId,
        int $attempt,
    ): void {
        $targets = ContentAudioScriptSegment::query()
            ->where('script_id', $scriptId)
            ->where('image_status', 'generating')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::IMAGE_BATCH_SIZE)
            ->get();

        foreach ($targets as $segment) {
            $mediaId = (string) Str::uuid();
            $path = ContentAudioScriptGeneratedImagePath::storagePath(
                $episodeId,
                $segment->id,
                $attempt,
                $mediaId,
            );

            try {
                $bytes = $this->imageGenerator->generate($segment->image_prompt ?: $segment->text);
                if (! $this->isWebp($bytes) || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                    throw new \RuntimeException(self::IMAGE_FAILURE_MESSAGE);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->recordImageFailure($jobId, $segment->id);
                $this->progressImages($jobId);

                continue;
            }

            try {
                if (! Storage::disk('media')->put($path, $bytes)) {
                    throw new \RuntimeException('Script image could not be persisted.');
                }
                $accepted = $this->persistImage($jobId, $segment->id, $mediaId, $path);
            } catch (Throwable $exception) {
                $this->mediaCleaner->deleteFiles([$path]);

                throw $exception;
            }
            if (! $accepted) {
                $this->mediaCleaner->deleteFiles([$path]);

                return;
            }
            $this->progressImages($jobId);
        }

        if (ContentAudioScriptSegment::query()
            ->where('script_id', $scriptId)
            ->where('image_status', 'generating')
            ->exists()) {
            $this->continueImages($jobId);

            return;
        }

        $this->completeImages($jobId);
    }

    private function persistImage(string $jobId, string $segmentId, string $mediaId, string $path): bool
    {
        $oldPath = null;
        $accepted = DB::transaction(function () use ($jobId, $mediaId, $path, $segmentId, &$oldPath): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()->whereKey($job->script_id)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE || ! $this->ownsAttempt($script, $job)) {
                return false;
            }

            $segment = ContentAudioScriptSegment::query()
                ->whereKey($segmentId)
                ->where('script_id', $script->id)
                ->lockForUpdate()
                ->first();
            if ($segment === null) {
                $this->terminalizeSuperseded($job);

                return false;
            }

            $oldMedia = $segment->imageMedia()->lockForUpdate()->first();
            $media = new ContentAudioScriptMedia;
            $media->id = $mediaId;
            $media->user_id = $job->user_id;
            $media->source_system = ContentSourceSystem::LEARNING_OS;
            $media->source_kind = 'generated';
            $media->source_filename = basename($path);
            $media->normalized_filename = basename($path);
            $media->media_kind = 'image';
            $media->content_type = 'image/webp';
            $media->storage_path = $path;
            $media->public_url = "/api/convolab/scripts/media/{$media->id}";
            $media->save();

            $segment->image_media_id = $media->id;
            $segment->image_status = 'ready';
            $segment->image_error_message = null;
            $segment->image_generated_at = now();
            $segment->save();

            if ($oldMedia !== null
                && $oldMedia->source_kind === 'generated'
                && $oldMedia->media_kind === 'image'
                && ! $oldMedia->segments()->exists()) {
                $oldPath = $oldMedia->storage_path;
                $oldMedia->delete();
            }

            return true;
        });
        if ($accepted && is_string($oldPath)) {
            $this->mediaCleaner->deleteFiles([$oldPath]);
        }

        return $accepted;
    }

    private function recordImageFailure(string $jobId, string $segmentId): void
    {
        DB::transaction(function () use ($jobId, $segmentId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()->whereKey($job->script_id)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE || ! $this->ownsAttempt($script, $job)) {
                return;
            }

            ContentAudioScriptSegment::query()
                ->whereKey($segmentId)
                ->where('script_id', $script->id)
                ->update([
                    'image_status' => 'error',
                    'image_error_message' => self::IMAGE_FAILURE_MESSAGE,
                ]);
        });
    }

    private function isWebp(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    private function completeImages(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()->whereKey($job->script_id)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE || ! $this->ownsAttempt($script, $job)) {
                if ($job !== null && ! ContentAudioScriptJob::isTerminal($job->state)) {
                    $this->terminalizeSuperseded($job);
                }

                return;
            }

            $total = $script->segments()->count();
            $ready = $script->segments()->where('image_status', 'ready')->whereNotNull('image_media_id')->count();
            $status = $ready === $total ? 'ready' : ($ready > 0 ? 'partial' : 'error');
            $failed = $total - $ready;
            $message = match ($status) {
                'partial' => "{$failed} script image(s) failed or are missing.",
                'error' => 'Script image generation failed.',
                default => null,
            };

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

    private function progress(string $jobId, int $progress): void
    {
        ContentAudioScriptGenerationJob::query()
            ->whereKey($jobId)
            ->where('state', ContentAudioScriptJob::STATE_ACTIVE)
            ->update(['progress' => min(99, max(5, $progress))]);
    }

    private function progressImages(string $jobId): void
    {
        $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->first();
        $targetCount = (int) data_get($job?->result, 'targetCount', 0);
        if ($job === null || $targetCount < 1) {
            return;
        }
        $remaining = ContentAudioScriptSegment::query()
            ->where('script_id', $job->script_id)
            ->where('image_status', 'generating')
            ->count();
        $processed = max(0, $targetCount - $remaining);
        $this->progress($jobId, 10 + (int) round(($processed / $targetCount) * 85));
    }

    private function continueImages(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioScriptGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            $script = $job === null ? null : ContentAudioScript::query()->whereKey($job->script_id)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioScriptJob::STATE_ACTIVE || ! $this->ownsAttempt($script, $job)) {
                return;
            }

            $job->state = ContentAudioScriptJob::STATE_WAITING;
            $job->started_at = null;
            $job->save();

            DB::afterCommit(static fn () => ProcessContentAudioScriptGeneration::dispatch($jobId));
        });
    }

    private function releaseClaim(string $jobId): void
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

    private function ownsAttempt(?ContentAudioScript $script, ContentAudioScriptGenerationJob $job): bool
    {
        if ($script === null || $script->episode_id !== $job->episode_id) {
            return false;
        }
        $attempt = $job->kind === ContentAudioScriptJob::KIND_RENDER
            ? $script->render_generation_attempt
            : $script->image_generation_attempt;

        return (int) $attempt === (int) $job->attempt;
    }

    private function terminalizeSuperseded(ContentAudioScriptGenerationJob $job): void
    {
        $job->state = ContentAudioScriptJob::STATE_FAILED;
        $job->error_message = ContentAudioScriptJob::FAILED_MESSAGE;
        $job->finished_at = now();
        $job->save();
    }

    private function deleteRenderPaths(string $episodeId, int $attempt): void
    {
        $paths = array_map(
            fn (array $speed): string => ContentAudioScriptRenderAudio::storagePath($episodeId, $attempt, $speed['speed']),
            ContentAudioScriptRenderAudio::SPEEDS,
        );
        $this->deleteOwnedRenderPaths($episodeId, $paths);
    }

    /** @param list<string|null> $paths */
    private function deleteOwnedRenderPaths(string $episodeId, array $paths): void
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
