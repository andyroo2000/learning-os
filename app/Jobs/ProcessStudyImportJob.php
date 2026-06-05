<?php

namespace App\Jobs;

use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStudyImportJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE_NAME = 'study-imports';

    public function __construct(
        public readonly string $importJobId,
    ) {
        $this->onQueue(self::QUEUE_NAME);
    }

    public function handle(ProcessStudyImportJobAction $processStudyImportJob): void
    {
        $processStudyImportJob->handle($this->importJobId);
    }
}
