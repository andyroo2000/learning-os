<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Support\Carbon;

class ExpireStaleProcessingStudyImportsAction
{
    public function handle(int $userId, ?Carbon $now = null): int
    {
        $now ??= now();

        return StudyImportJob::query()
            ->where('user_id', $userId)
            ->where('status', StudyImportStatus::Processing->value)
            ->whereNotNull('started_at')
            ->where('started_at', '<', $now->copy()->subMinutes(StudyImportJob::PROCESSING_TIMEOUT_MINUTES))
            ->update([
                'status' => StudyImportStatus::Failed->value,
                'error_message' => 'Study import timed out before completion.',
                'completed_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
