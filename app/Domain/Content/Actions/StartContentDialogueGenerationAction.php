<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentDialogueData;
use App\Domain\Content\Exceptions\ContentDialogueGenerationConflictException;
use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StartContentDialogueGenerationAction
{
    /** @param callable(string): void $afterCommit */
    public function handle(
        int $userId,
        string $convoLabUserId,
        GenerateContentDialogueData $data,
        callable $afterCommit,
    ): ?ContentDialogueGenerationJob {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);

        return DB::transaction(function () use ($afterCommit, $convoLabUserId, $data, $userId): ?ContentDialogueGenerationJob {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $episode = ContentEpisode::query()
                ->whereKey($data->episodeId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($episode === null) {
                return null;
            }
            if ($episode->status === 'generating') {
                throw ContentDialogueGenerationConflictException::alreadyGenerating();
            }

            $episode->source_system = ContentSourceSystem::LEARNING_OS;
            $episode->status = 'generating';
            $episode->dialogue_generation_attempt = ((int) $episode->dialogue_generation_attempt) + 1;
            $episode->save();

            $job = new ContentDialogueGenerationJob;
            $job->id = (string) Str::uuid();
            $job->episode_id = $episode->id;
            $job->user_id = $userId;
            $job->convolab_user_id = $convoLabUserId;
            $job->attempt = $episode->dialogue_generation_attempt;
            $job->state = ContentDialogueGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->input = $data->toArray();
            $job->save();

            DB::afterCommit(static fn () => $afterCommit($job->id));

            return $job;
        });
    }
}
