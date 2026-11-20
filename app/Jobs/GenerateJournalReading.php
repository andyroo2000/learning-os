<?php

namespace App\Jobs;

use App\Domain\Journal\Actions\ProcessJournalReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Throwable;

final class GenerateJournalReading implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 85;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $analysisId, public readonly string $readingId)
    {
        // Even local sync configurations must acknowledge a save before contacting the model.
        $this->onConnection(config('queue.default') === 'sync' ? 'database' : config('queue.default'));
        $this->onQueue('default');
    }

    /** Called after commit; queue outages must never turn a saved edit into a failed save. */
    public static function enqueue(string $analysisId, string $readingId): void
    {
        $job = new self($analysisId, $readingId);
        try {
            Bus::dispatch($job);
        } catch (Throwable $error) {
            report($error);
            $job->failed($error);
        }
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->analysisId.':'.$this->readingId))->releaseAfter(10)->expireAfter(90)];
    }

    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(ProcessJournalReading $process): void
    {
        $process->handle($this->analysisId, $this->readingId);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            // Laravel does not inject dependencies into failed(); don't serialize a service.
            app(ProcessJournalReading::class)->fail($this->analysisId, $this->readingId);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
