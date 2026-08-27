<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Support\Facades\DB;

class FailDailyAudioPracticeAction
{
    public function handle(
        string $practiceId,
        string $message,
        ?string $generationRunId = null,
        bool $requireMatchingRun = false,
    ): bool {
        $practiceId = strtolower(trim($practiceId));
        $generationRunId = $generationRunId === null ? null : strtolower(trim($generationRunId));
        if (! DailyAudioPracticeId::isValid($practiceId)
            || ($generationRunId !== null && ! DailyAudioPracticeId::isValid($generationRunId))) {
            return false;
        }

        return DB::transaction(function () use (
            $generationRunId,
            $message,
            $practiceId,
            $requireMatchingRun,
        ): bool {
            $practice = DailyAudioPractice::query()
                ->whereKey($practiceId)
                ->when(
                    $requireMatchingRun,
                    fn ($query) => $query->where('generation_run_id', $generationRunId),
                )
                ->lockForUpdate()
                ->first();

            if ($practice === null || $practice->status !== 'generating') {
                return false;
            }

            $practice->status = 'error';
            $practice->error_message = $message;
            $practice->save();

            DailyAudioPracticeTrack::query()
                ->where('practice_id', $practice->id)
                ->whereIn('status', ['draft', 'generating'])
                ->update([
                    'status' => 'error',
                    'error_message' => $message,
                ]);

            return true;
        });
    }
}
