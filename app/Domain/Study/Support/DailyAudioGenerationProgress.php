<?php

namespace App\Domain\Study\Support;

use Illuminate\Support\Facades\DB;

final class DailyAudioGenerationProgress
{
    private int $last = -1;

    public function __construct(private string $practiceId, private ?string $runId) {}

    public function report(int $percent): void
    {
        if ($percent === $this->last) {
            return;
        }
        // A superseded job must never change the replacement's progress or retry deadline.
        DB::table('daily_audio_practices')->where('id', $this->practiceId)
            ->where('generation_run_id', $this->runId)->where('status', 'generating')
            ->update(['generation_progress' => $percent]);
        $this->last = $percent;
    }
}
