<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Support\ContentImageGenerationJobId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentImageGenerationJobAction
{
    public function handle(int $userId, string $convoLabUserId, string $jobId): ?ContentImageGenerationJob
    {
        return ContentImageGenerationJob::query()
            ->whereKey(ContentImageGenerationJobId::normalize($jobId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();
    }
}
