<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;

class GetCurrentStudyImportJobAction
{
    public function handle(int $userId): ?StudyImportJob
    {
        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->whereIn('status', [
                StudyImportStatus::Pending->value,
                StudyImportStatus::Processing->value,
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }
}
