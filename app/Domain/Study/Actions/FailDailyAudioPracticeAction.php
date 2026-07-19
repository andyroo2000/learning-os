<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Support\Facades\DB;

class FailDailyAudioPracticeAction
{
    public function handle(string $practiceId, string $message): bool
    {
        $practiceId = strtolower(trim($practiceId));
        if (! DailyAudioPracticeId::isValid($practiceId)) {
            return false;
        }

        return DB::transaction(function () use ($message, $practiceId): bool {
            $practice = DailyAudioPractice::query()
                ->whereKey($practiceId)
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
