<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentDialogueGenerationJob;
use App\Domain\Content\Support\ContentDialogueGeneration;
use App\Domain\Content\Support\ContentDialogueJobId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentDialogueGenerationJobAction
{
    public function handle(int $userId, string $convoLabUserId, string $jobId): ?ContentDialogueGenerationJob
    {
        $job = ContentDialogueGenerationJob::query()
            ->whereKey(ContentDialogueJobId::normalize($jobId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();

        if ($job?->state === ContentDialogueGeneration::STATE_COMPLETED) {
            $job->load([
                'episode.dialogue.sentences' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'episode.dialogue.speakers',
            ]);
        }

        return $job;
    }
}
