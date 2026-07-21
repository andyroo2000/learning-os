<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Support\ContentAudioScriptJobId;
use App\Domain\Content\Support\ConvoLabUserId;

final class ShowContentAudioScriptGenerationJobAction
{
    public function handle(int $userId, string $convoLabUserId, string $jobId): ?ContentAudioScriptGenerationJob
    {
        return ContentAudioScriptGenerationJob::query()
            ->whereKey(ContentAudioScriptJobId::normalize($jobId))
            ->where('user_id', $userId)
            ->where('convolab_user_id', ConvoLabUserId::normalize($convoLabUserId))
            ->first();
    }
}
