<?php

namespace App\Jobs;

use App\Domain\Journal\Actions\ProcessJournalReply;
use App\Domain\Journal\Data\JournalReplyAttempt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeJournalReply implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 120;

    public readonly JournalReplyAttempt $attempt;

    public function __construct(public readonly string $replyId)
    {
        $this->attempt = new JournalReplyAttempt($replyId, (string) Str::ulid());
        $this->onQueue('default')->afterCommit();
    }

    public function handle(ProcessJournalReply $process): void
    {
        $process->handle($this->attempt);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessJournalReply::class)->fail($this->attempt);
    }

    public function uniqueId(): string
    {
        return $this->replyId;
    }
}
