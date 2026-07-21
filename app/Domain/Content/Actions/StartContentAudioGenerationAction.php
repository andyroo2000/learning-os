<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioData;
use App\Domain\Content\Exceptions\ContentAudioGenerationConflictException;
use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentAudioGeneration;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StartContentAudioGenerationAction
{
    /** @param callable(string): void $afterCommit */
    public function handle(
        int $userId,
        string $convoLabUserId,
        GenerateContentAudioData $data,
        callable $afterCommit,
    ): ?ContentAudioGenerationJob {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);

        return DB::transaction(function () use ($afterCommit, $convoLabUserId, $data, $userId): ?ContentAudioGenerationJob {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $episode = ContentEpisode::query()
                ->whereKey($data->episodeId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($episode === null || $episode->dialogue?->id !== $data->dialogueId) {
                return null;
            }

            $existing = ContentAudioGenerationJob::query()
                ->where('episode_id', $episode->id)
                ->whereIn('state', [ContentAudioGeneration::STATE_WAITING, ContentAudioGeneration::STATE_ACTIVE])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (GenerateContentAudioData::fromInput($existing->input)->toArray() !== $data->toArray()) {
                    throw ContentAudioGenerationConflictException::differentRequestInProgress();
                }

                return $existing;
            }

            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->audio_generation_attempt = ((int) $episode->audio_generation_attempt) + 1;
            $episode->save();

            $job = new ContentAudioGenerationJob;
            $job->id = (string) Str::uuid();
            $job->episode_id = $episode->id;
            $job->dialogue_id = $data->dialogueId;
            $job->user_id = $userId;
            $job->convolab_user_id = $convoLabUserId;
            $job->attempt = $episode->audio_generation_attempt;
            $job->state = ContentAudioGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->input = $data->toArray();
            $job->save();

            DB::afterCommit(static fn () => $afterCommit($job->id));

            return $job;
        });
    }
}
