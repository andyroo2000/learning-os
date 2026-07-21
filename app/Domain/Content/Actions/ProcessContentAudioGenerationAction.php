<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioData;
use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentSentence;
use App\Domain\Content\Results\ContentEpisodeAudioTrackResult;
use App\Domain\Content\Services\ContentEpisodeAudioAssembler;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentAudioJobId;
use App\Domain\Content\Support\ContentEpisodeAudio;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class ProcessContentAudioGenerationAction
{
    public function __construct(private ContentEpisodeAudioAssembler $assembler) {}

    public function handle(string $jobId): void
    {
        $jobId = ContentAudioJobId::normalize($jobId);
        $claimed = $this->claim($jobId);
        if ($claimed === null) {
            return;
        }

        $generated = [];
        try {
            $sentences = ContentSentence::query()
                ->where('dialogue_id', $claimed['data']->dialogueId)
                ->with('speaker')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->all();
            if ($sentences === []) {
                throw new \RuntimeException('Dialogue has no sentences to synthesize.');
            }

            $tracks = $this->tracks($claimed['data']);
            foreach ($tracks as $index => $track) {
                $generated[] = $this->assembler->assemble(
                    $claimed['data']->episodeId,
                    $claimed['attempt'],
                    $track['track'],
                    $track['speed'],
                    $sentences,
                    $claimed['data']->pauseMode,
                );
                $this->progress($jobId, (int) round((($index + 1) / count($tracks)) * 90));
            }

            $oldPaths = $this->complete($jobId, $claimed['data'], $generated);
            if ($oldPaths === null) {
                $this->deletePaths(
                    $claimed['data']->episodeId,
                    $this->attemptPaths($claimed['data'], $claimed['attempt']),
                );

                return;
            }
            $this->deletePaths($claimed['data']->episodeId, $oldPaths);
        } catch (Throwable $exception) {
            $this->deletePaths(
                $claimed['data']->episodeId,
                $this->attemptPaths($claimed['data'], $claimed['attempt']),
            );
            try {
                $this->releaseClaim($jobId);
            } catch (Throwable $releaseException) {
                report($releaseException);
            }

            throw $exception;
        }
    }

    /** @return null|array{data: GenerateContentAudioData, attempt: int} */
    private function claim(string $jobId): ?array
    {
        return DB::transaction(function () use ($jobId): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentAudioGeneration::isTerminal($job->state)) {
                return null;
            }
            if ($job->state === ContentAudioGeneration::STATE_ACTIVE
                && $job->started_at !== null
                && $job->started_at->isAfter(now()->subSeconds(ContentAudioGeneration::ACTIVE_STALE_AFTER_SECONDS))) {
                return null;
            }

            $episode = ContentEpisode::query()->whereKey($job->episode_id)->lockForUpdate()->first();
            if (! $this->ownsAttempt($episode, $job)) {
                $job->state = ContentAudioGeneration::STATE_FAILED;
                $job->error_message = ContentAudioGeneration::FAILED_MESSAGE;
                $job->finished_at = now();
                $job->save();

                return null;
            }

            $job->state = ContentAudioGeneration::STATE_ACTIVE;
            $job->progress = 5;
            $job->started_at = now();
            $job->save();

            return [
                'data' => GenerateContentAudioData::fromInput($job->input),
                'attempt' => (int) $job->attempt,
            ];
        });
    }

    private function progress(string $jobId, int $progress): void
    {
        ContentAudioGenerationJob::query()
            ->whereKey($jobId)
            ->where('state', ContentAudioGeneration::STATE_ACTIVE)
            ->update(['progress' => min(99, max(5, $progress))]);
    }

    private function releaseClaim(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioGeneration::STATE_ACTIVE) {
                return;
            }

            $job->state = ContentAudioGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->started_at = null;
            $job->save();
        });
    }

    /**
     * @param  list<ContentEpisodeAudioTrackResult>  $results
     * @return list<string>|null
     */
    private function complete(string $jobId, GenerateContentAudioData $data, array $results): ?array
    {
        return DB::transaction(function () use ($data, $jobId, $results): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentAudioGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || $job->state !== ContentAudioGeneration::STATE_ACTIVE) {
                return null;
            }

            $episode = ContentEpisode::query()->whereKey($job->episode_id)->lockForUpdate()->first();
            if (! $this->ownsAttempt($episode, $job)) {
                $job->state = ContentAudioGeneration::STATE_FAILED;
                $job->error_message = ContentAudioGeneration::FAILED_MESSAGE;
                $job->finished_at = now();
                $job->save();

                return null;
            }

            $oldPaths = [];
            $payload = [];
            $sentenceUpdates = [];
            foreach ($results as $result) {
                [$urlField, $pathField, $startField, $endField] = $this->fields($result->track);
                if (is_string($episode->{$pathField})) {
                    $oldPaths[] = $episode->{$pathField};
                }
                $url = ContentEpisodeAudio::audioUrl($episode->id, $result->track);
                $episode->{$urlField} = $url;
                $episode->{$pathField} = $result->storagePath;
                foreach ($result->sentenceTimings as $sentenceId => $timing) {
                    $sentenceUpdates[$sentenceId] = [
                        ...($sentenceUpdates[$sentenceId] ?? []),
                        $startField => $timing['startTime'],
                        $endField => $timing['endTime'],
                    ];
                }
                $payload[] = [
                    'speed' => $this->resultSpeed($result->track, $data->speed),
                    'audioUrl' => $url,
                    'duration' => $result->durationSeconds * 1_000,
                ];
            }
            foreach ($sentenceUpdates as $sentenceId => $timings) {
                ContentSentence::query()
                    ->whereKey($sentenceId)
                    ->where('dialogue_id', $job->dialogue_id)
                    ->update($timings);
            }

            if ($data->mode === GenerateContentAudioData::MODE_SINGLE) {
                $episode->audio_speed = $data->speed;
            }
            $episode->save();

            $job->state = ContentAudioGeneration::STATE_COMPLETED;
            $job->progress = 100;
            $job->result = $data->mode === GenerateContentAudioData::MODE_SINGLE
                ? [
                    'audioUrl' => $payload[0]['audioUrl'],
                    'duration' => $payload[0]['duration'],
                    'sentenceTimings' => $results[0]->sentenceTimings,
                ]
                : $payload;
            $job->error_message = null;
            $job->finished_at = now();
            $job->save();

            return array_values(array_unique($oldPaths));
        });
    }

    /** @return list<array{track: string, speed: float}> */
    private function tracks(GenerateContentAudioData $data): array
    {
        if ($data->mode === GenerateContentAudioData::MODE_ALL_SPEEDS) {
            return [
                ['track' => ContentEpisodeAudio::TRACK_SLOW, 'speed' => 0.7],
                ['track' => ContentEpisodeAudio::TRACK_MEDIUM, 'speed' => 0.85],
                ['track' => ContentEpisodeAudio::TRACK_NORMAL, 'speed' => 1.0],
            ];
        }

        return [[
            'track' => ContentEpisodeAudio::TRACK_DEFAULT,
            'speed' => match ($data->speed) {
                'very-slow' => 0.65,
                'slow' => 0.7,
                'medium' => 0.85,
                default => 1.0,
            },
        ]];
    }

    /** @return array{string, string, string, string} */
    private function fields(string $track): array
    {
        return match ($track) {
            ContentEpisodeAudio::TRACK_SLOW => ['audio_url_0_7', 'audio_storage_path_0_7', 'start_time_0_7', 'end_time_0_7'],
            ContentEpisodeAudio::TRACK_MEDIUM => ['audio_url_0_85', 'audio_storage_path_0_85', 'start_time_0_85', 'end_time_0_85'],
            ContentEpisodeAudio::TRACK_NORMAL => ['audio_url_1_0', 'audio_storage_path_1_0', 'start_time_1_0', 'end_time_1_0'],
            default => ['audio_url', 'audio_storage_path', 'start_time', 'end_time'],
        };
    }

    private function resultSpeed(string $track, string $fallback): string
    {
        return match ($track) {
            ContentEpisodeAudio::TRACK_SLOW => 'slow',
            ContentEpisodeAudio::TRACK_MEDIUM => 'medium',
            ContentEpisodeAudio::TRACK_NORMAL => 'normal',
            default => $fallback,
        };
    }

    /** @return list<string> */
    private function attemptPaths(GenerateContentAudioData $data, int $attempt): array
    {
        return array_map(
            fn (array $track): string => ContentEpisodeAudio::storagePath(
                $data->episodeId,
                $attempt,
                $track['track'],
            ),
            $this->tracks($data),
        );
    }

    /** @param list<string> $paths */
    private function deletePaths(string $episodeId, array $paths): void
    {
        $disk = Storage::disk((string) config('content_audio.disk'));
        foreach (array_unique($paths) as $path) {
            if (ContentEpisodeAudio::ownsPath($episodeId, $path)) {
                try {
                    $disk->delete($path);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function ownsAttempt(?ContentEpisode $episode, ContentAudioGenerationJob $job): bool
    {
        return $episode !== null
            && (int) $episode->audio_generation_attempt === (int) $job->attempt
            && $episode->dialogue?->id === $job->dialogue_id;
    }
}
