<?php

namespace App\Jobs;

use App\Domain\Journal\Actions\ProcessJournalClarification;
use App\Domain\Journal\Data\JournalClarificationAttempt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ClarifyJournalEntry implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public readonly JournalClarificationAttempt $attempt;

    public function __construct(string $id, int $revision)
    {
        $this->attempt = new JournalClarificationAttempt($id, (string) Str::ulid(), $revision);
        $this->onQueue('default')->afterCommit();
    }

    public function handle(ProcessJournalClarification $process): void
    {
        $process->handle($this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        // Laravel's failed hook does not inject services; keep them out of the serialized job.
        app(ProcessJournalClarification::class)->fail($this->attempt);
    }
}
