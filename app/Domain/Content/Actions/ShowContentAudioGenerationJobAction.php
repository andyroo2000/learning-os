<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioGenerationJob;
use App\Domain\Content\Support\ContentAudioJobId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentAudioGenerationJobAction
{
    public function handle(int $userId, string $convoLabUserId, string $jobId): ?ContentAudioGenerationJob
    {
        return ContentAudioGenerationJob::query()
            ->whereKey(ContentAudioJobId::normalize($jobId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();
    }
}
