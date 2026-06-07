<?php

namespace App\Jobs;

use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Domain\Study\Support\StudyImportJobFailureMarker;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessStudyImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'study-imports';

    public const EXHAUSTED_ERROR_MESSAGE = 'Study import processing failed after retries.';

    // Duplicate jobs are harmless because the processor locks and guards terminal states,
    // but uniqueness avoids redundant archive reads and importer contention. No uniqueFor
    // override: after completion or failure, terminal state guards own later dispatches.
    public int $tries = 4;

    public function __construct(
        public readonly string $importJobId,
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ProcessStudyImportJobAction $processStudyImportJob): void
    {
        $processStudyImportJob->handle($this->importJobId);
    }

    public function failed(Throwable $exception): void
    {
        $importJobId = CanonicalUlid::normalize($this->importJobId);
        $now = now();

        DB::transaction(static function () use ($importJobId, $now): void {
            $importJob = StudyImportJob::query()
                ->whereKey($importJobId)
                ->lockForUpdate()
                ->first();

            if ($importJob === null || ! self::shouldMarkFailed($importJob)) {
                return;
            }

            StudyImportJobFailureMarker::markFailed($importJob, self::EXHAUSTED_ERROR_MESSAGE, $now);
        });
    }

    public function uniqueId(): string
    {
        return CanonicalUlid::normalize($this->importJobId);
    }

    private static function shouldMarkFailed(StudyImportJob $importJob): bool
    {
        return in_array($importJob->status, [
            StudyImportStatus::Pending,
            StudyImportStatus::Processing,
        ], true);
    }
}
