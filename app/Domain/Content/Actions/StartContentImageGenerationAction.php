<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentImagesData;
use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StartContentImageGenerationAction
{
    /** @param callable(string): void $afterCommit */
    public function handle(
        int $userId,
        string $convoLabUserId,
        GenerateContentImagesData $data,
        callable $afterCommit,
    ): ?ContentImageGenerationJob {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);

        return DB::transaction(function () use ($afterCommit, $convoLabUserId, $data, $userId): ?ContentImageGenerationJob {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $dialogue = ContentDialogue::query()
                ->whereKey($data->dialogueId)
                ->where('episode_id', $data->episodeId)
                ->whereHas('episode', fn ($query) => $query
                    ->where('user_id', $userId)
                    ->where('convolab_user_id', $convoLabUserId))
                ->lockForUpdate()
                ->first();
            if ($dialogue === null) {
                return null;
            }

            $existing = ContentImageGenerationJob::query()
                ->where('dialogue_id', $dialogue->id)
                ->whereIn('state', [ContentImageGeneration::STATE_WAITING, ContentImageGeneration::STATE_ACTIVE])
                ->latest('created_at')
                ->latest('id')
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $job = new ContentImageGenerationJob;
            $job->id = (string) Str::uuid();
            $job->episode_id = $data->episodeId;
            $job->dialogue_id = $data->dialogueId;
            $job->user_id = $userId;
            $job->convolab_user_id = $convoLabUserId;
            $job->state = ContentImageGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->image_count = $data->imageCount;
            $job->save();

            DB::afterCommit(static fn () => $afterCommit($job->id));

            return $job;
        });
    }
}
