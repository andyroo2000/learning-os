<?php

namespace App\Jobs;

use App\Domain\Journal\Actions\ProcessJournalAnalysis;
use App\Domain\Journal\Data\JournalAnalysisAttempt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeJournalEntry implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 180;

    public readonly JournalAnalysisAttempt $attempt;

    public function __construct(public readonly string $analysisId)
    {
        $this->attempt = new JournalAnalysisAttempt($analysisId, (string) Str::ulid());
        $this->onQueue('default')->afterCommit();
    }

    public function handle(ProcessJournalAnalysis $process): void
    {
        $process->handle($this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessJournalAnalysis::class)->fail($this->attempt);
    }

    public function uniqueId(): string
    {
        return $this->analysisId;
    }
}
