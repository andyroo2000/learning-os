<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyImportJob;

class GetCurrentStudyImportJobAction
{
    public function handle(int $userId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->active()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
